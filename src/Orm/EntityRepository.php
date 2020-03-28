<?php

namespace EasyCorp\Bundle\EasyAdminBundle\Orm;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Fields;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Orm\EntityRepositoryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Factory\FormFactory;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\FilterRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Provider\AdminContextProvider;
use Symfony\Component\Form\FormFactoryInterface;

final class EntityRepository implements EntityRepositoryInterface
{
    private $adminContextProvider;
    private $doctrine;
    private $filterRegistry;
    private $formFactory;

    public function __construct(AdminContextProvider $adminContextProvider, ManagerRegistry $doctrine, FilterRegistry $filterRegistry, FormFactory $formFactory)
    {
        $this->adminContextProvider = $adminContextProvider;
        $this->doctrine = $doctrine;
        $this->filterRegistry = $filterRegistry;
        $this->formFactory = $formFactory;
    }

    public function createQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, array $filtersDto): QueryBuilder
    {
        $entityManager = $this->doctrine->getManagerForClass($entityDto->getFqcn());

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('entity')
            ->from($entityDto->getFqcn(), 'entity')
        ;

        if (!empty($searchDto->getQuery())) {
            $this->addSearchClause($queryBuilder, $searchDto, $entityDto);
        }

        if (!empty($searchDto->getAppliedFilters())) {
            $this->addFilterClause($queryBuilder, $searchDto, $entityDto, $filtersDto, $fields);
        }

        $this->addOrderClause($queryBuilder, $searchDto, $entityDto);

        return $queryBuilder;
    }

    private function addSearchClause(QueryBuilder $queryBuilder, SearchDto $searchDto, EntityDto $entityDto): void
    {
        $query = $searchDto->getQuery();
        $lowercaseQuery = mb_strtolower($query);
        $isNumericQuery = is_numeric($query);
        $isSmallIntegerQuery = ctype_digit($query) && $query >= -32768 && $query <= 32767;
        $isIntegerQuery = ctype_digit($query) && $query >= -2147483648 && $query <= 2147483647;
        $isUuidQuery = 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $query);

        $dqlParameters = [
            // adding '0' turns the string into a numeric value
            'numeric_query' => is_numeric($query) ? 0 + $query : $query,
            'uuid_query' => $query,
            'text_query' => '%'.$lowercaseQuery.'%',
            'words_query' => explode(' ', $lowercaseQuery),
        ];

        $entitiesAlreadyJoined = [];
        $configuredSearchableProperties = $searchDto->getSearchableProperties();
        $searchableProperties = empty($configuredSearchableProperties) ? $entityDto->getAllPropertyNames() : $configuredSearchableProperties;
        foreach ($searchableProperties as $propertyName) {
            $entityName = 'entity';
            $propertyDataType = $entityDto->getPropertyDataType($propertyName);

            if ($entityDto->isAssociation($propertyName)) {
                // support arbitrarily nested associations (e.g. foo.bar.baz.qux)
                $associatedProperties = explode('.', $propertyName);
                for ($i = 0; $i < \count($associatedProperties) - 1; ++$i) {
                    $associatedEntityName = $associatedProperties[$i];
                    $associatedPropertyName = $associatedProperties[$i + 1];

                    if (!\in_array($associatedEntityName, $entitiesAlreadyJoined, true)) {
                        $parentEntityName = 0 === $i ? 'entity' : $associatedProperties[$i - 1];
                        $queryBuilder->leftJoin($parentEntityName.'.'.$associatedEntityName, $associatedEntityName);
                        $entitiesAlreadyJoined[] = $associatedEntityName;
                    }

                    $entityName = $associatedEntityName;
                    $propertyName = $associatedPropertyName;
                }
            }

            $isSmallIntegerProperty = 'smallint' === $propertyDataType;
            $isIntegerProperty = 'integer' === $propertyDataType;
            $isNumericProperty = \in_array($propertyDataType, ['number', 'bigint', 'decimal', 'float']);
            // 'citext' is a PostgreSQL extension (https://github.com/EasyCorp/EasyAdminBundle/issues/2556)
            $isTextProperty = \in_array($propertyDataType, ['string', 'text', 'citext', 'array', 'simple_array']);
            $isGuidProperty = \in_array($propertyDataType, ['guid', 'uuid']);

            // this complex condition is needed to avoid issues on PostgreSQL databases
            if (
                ($isSmallIntegerProperty && $isSmallIntegerQuery) ||
                ($isIntegerProperty && $isIntegerQuery) ||
                ($isNumericProperty && $isNumericQuery)
            ) {
                $queryBuilder->orWhere(sprintf('%s.%s = :query_for_numbers', $entityName, $propertyName))
                    ->setParameter('query_for_numbers', $dqlParameters['numeric_query']);
            } elseif ($isGuidProperty && $isUuidQuery) {
                $queryBuilder->orWhere(sprintf('%s.%s = :query_for_uuids', $entityName, $propertyName))
                    ->setParameter('query_for_uuids', $dqlParameters['uuid_query']);
            } elseif ($isTextProperty) {
                $queryBuilder->orWhere(sprintf('LOWER(%s.%s) LIKE :query_for_text', $entityName, $propertyName))
                    ->setParameter('query_for_text', $dqlParameters['text_query']);
                $queryBuilder->orWhere(sprintf('LOWER(%s.%s) IN (:query_as_words)', $entityName, $propertyName))
                    ->setParameter('query_as_words', $dqlParameters['words_query']);
            }
        }
    }

    private function addOrderClause(QueryBuilder $queryBuilder, SearchDto $searchDto, EntityDto $entityDto): void
    {
        foreach ($searchDto->getSort() as $sortProperty => $sortOrder) {
            $sortFieldIsDoctrineAssociation = $entityDto->isAssociation($sortProperty);

            if ($sortFieldIsDoctrineAssociation) {
                $sortFieldParts = explode('.', $sortProperty, 2);
                $queryBuilder->leftJoin('entity.'.$sortFieldParts[0], $sortFieldParts[0]);
                $queryBuilder->addOrderBy($sortProperty, $sortOrder);
            } else {
                $queryBuilder->addOrderBy('entity.'.$sortProperty, $sortOrder);
            }
        }
    }

    private function addFilterClause(QueryBuilder $queryBuilder, SearchDto $searchDto, EntityDto $entityDto, array $configuredFilters, FieldCollection $fields): void
    {
        $filtersForm = $this->formFactory->createFiltersForm($this->adminContextProvider->getContext(), $fields, $entityDto);
        if (!$filtersForm->isSubmitted()) {
            return;
        }

        $appliedFilters = $searchDto->getAppliedFilters();
        $i = 0;
        foreach ($filtersForm as $filterForm) {
            $propertyName = $filterForm->getName();

            /** @var TextFilter $filter */
            $filter = $configuredFilters[$propertyName];
            // this filter is not defined or not applied
            if (null === $filter || !isset($appliedFilters[$propertyName])) {
                continue;
            }

            // if the form filter is not valid then we should not apply the filter
            if (!$filterForm->isValid()) {
                continue;
            }

            $submittedData = $filterForm->getData();
            if (!\is_array($submittedData)) {
                $submittedData = [
                    'comparison' => ComparisonType::EQ,
                    'value' => $submittedData,
                ];
            }
            // TODO: pass the field being filtered too
            $filter->apply($queryBuilder, FilterDataDto::new($i, $propertyName, current($queryBuilder->getRootAliases()), $submittedData));

            ++$i;
        }
    }
}
