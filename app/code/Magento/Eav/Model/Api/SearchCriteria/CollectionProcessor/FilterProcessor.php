<?php
/**
 * Copyright 2016 Adobe
 * All Rights Reserved.
 */
namespace Magento\Eav\Model\Api\SearchCriteria\CollectionProcessor;

use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteria\CollectionProcessor\FilterProcessor\CustomFilterInterface;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Select;

/**
 *  SearchCriteria FilterProcessor
 */
class FilterProcessor implements CollectionProcessorInterface
{
    /**
     * @var CustomFilterInterface[]
     */
    private $customFilters;

    /**
     * @var array
     */
    private $fieldMapping;

    /**
     * @param CustomFilterInterface[] $customFilters
     * @param array $fieldMapping
     */
    public function __construct(
        array $customFilters = [],
        array $fieldMapping = []
    ) {
        $this->customFilters = $customFilters;
        $this->fieldMapping = $fieldMapping;
    }

    /**
     * Apply Search Criteria Filters to collection
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @param AbstractDb $collection
     * @return void
     */
    public function process(SearchCriteriaInterface $searchCriteria, AbstractDb $collection)
    {
        foreach ($searchCriteria->getFilterGroups() as $group) {
            $this->addFilterGroupToCollection($group, $collection);
        }
    }

    /**
     * Add FilterGroup to the collection
     *
     * @param FilterGroup $filterGroup
     * @param AbstractDb $collection
     * @return void
     */
    private function addFilterGroupToCollection(
        FilterGroup $filterGroup,
        AbstractDb $collection
    ) {
        $filters = $filterGroup->getFilters() ?: [];
        $customConditions = count($filters) > 1 ? $this->getCustomFilterConditions($filters, $collection) : null;
        if ($customConditions) {
            $this->addOrConditions($customConditions['conditions'], $customConditions['plain'], $collection);
            return;
        }

        $fields = [];
        foreach ($filters as $filter) {
            $isApplied = false;
            $customFilter = $this->getCustomFilterForField($filter->getField());
            if ($customFilter) {
                $isApplied = $customFilter->apply($filter, $collection);
            }

            if (!$isApplied) {
                $fields[] = $this->getFieldCondition($filter);
            }
        }

        if ($fields) {
            $collection->addFieldToFilter($fields);
        }
    }

    /**
     * Isolate the WHERE condition each custom filter of a group adds, so the group can be OR-ed
     *
     * Each custom filter is applied to a clone of the collection. Returns null when a custom filter adds a join,
     * a bind parameter or no WHERE condition: such a filter cannot be OR-ed safely and the group keeps the
     * previous behaviour.
     * The clone copies only the select; state a custom filter keeps in collection properties (e.g. product
     * limitation filters) is shared, so a custom filter must express its condition in the select to be OR-ed.
     *
     * @param Filter[] $filters
     * @param AbstractDb $collection
     * @return array|null ['conditions' => string[], 'plain' => array[]]
     */
    private function getCustomFilterConditions(array $filters, AbstractDb $collection): ?array
    {
        $conditions = [];
        $plain = [];
        foreach ($filters as $filter) {
            $customFilter = $this->getCustomFilterForField($filter->getField());
            if (!$customFilter) {
                $plain[] = $this->getFieldCondition($filter);
                continue;
            }
            $probe = clone $collection;
            $from = $probe->getSelect()->getPart(Select::FROM);
            $probe->getSelect()->reset(Select::WHERE);
            if (!$customFilter->apply($filter, $probe)) {
                $plain[] = $this->getFieldCondition($filter);
                continue;
            }
            $where = $probe->getSelect()->getPart(Select::WHERE);
            if (!$where
                || $probe->getSelect()->getPart(Select::FROM) !== $from
                || $probe->getSelect()->getBind() !== $collection->getSelect()->getBind()
            ) {
                return null;
            }
            $conditions[] = implode(' ', $where);
        }

        return $conditions ? ['conditions' => $conditions, 'plain' => $plain] : null;
    }

    /**
     * Add the custom filter conditions and the plain field conditions of one group as a single OR condition
     *
     * @param string[] $conditions
     * @param array[] $plain
     * @param AbstractDb $collection
     * @return void
     */
    private function addOrConditions(array $conditions, array $plain, AbstractDb $collection): void
    {
        $select = $collection->getSelect();
        if ($plain) {
            $where = $select->getPart(Select::WHERE);
            $select->reset(Select::WHERE);
            $collection->addFieldToFilter($plain);
            $conditions[] = implode(' ', $select->getPart(Select::WHERE));
            $select->setPart(Select::WHERE, $where);
        }
        // Already quoted SQL: TYPE_CONDITION stops where() from treating a literal ? as a placeholder
        $select->where('(' . implode(') OR (', $conditions) . ')', null, Select::TYPE_CONDITION);
    }

    /**
     * Build the addFieldToFilter() condition for a filter without a custom filter
     *
     * @param Filter $filter
     * @return array
     */
    private function getFieldCondition(Filter $filter): array
    {
        $condition = $filter->getConditionType() ? $filter->getConditionType() : 'eq';
        return ['attribute' => $this->getFieldMapping($filter->getField()), $condition => $filter->getValue()];
    }

    /**
     * Return custom filters for field if exists
     *
     * @param string $field
     * @return CustomFilterInterface|null
     * @throws \InvalidArgumentException
     */
    private function getCustomFilterForField($field)
    {
        $filter = null;
        if (isset($this->customFilters[$field])) {
            $filter = $this->customFilters[$field];
            if (!($this->customFilters[$field] instanceof CustomFilterInterface)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Filter for %s must implement %s interface.',
                        $field,
                        CustomFilterInterface::class
                    )
                );
            }
        }
        return $filter;
    }

    /**
     * Return mapped field name
     *
     * @param string $field
     * @return string
     */
    private function getFieldMapping($field)
    {
        return $this->fieldMapping[$field] ?? $field;
    }
}
