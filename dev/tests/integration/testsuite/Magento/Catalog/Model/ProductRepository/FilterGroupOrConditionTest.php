<?php
/**
 * Copyright 2026 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Catalog\Model\ProductRepository;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Filters inside one filter group are combined with OR, filter groups with AND (magento/magento2#16425).
 *
 * Fixture: category A = {p1, p3}, category B = {p2, p3}; `or-filter-q?x` and `or-filter-q'x` in no category.
 */
#[
    DataFixture(CategoryFixture::class, as: 'catA'),
    DataFixture(CategoryFixture::class, as: 'catB'),
    DataFixture(ProductFixture::class, ['sku' => 'or-filter-p1', 'category_ids' => ['$catA.id$']], as: 'p1'),
    DataFixture(ProductFixture::class, ['sku' => 'or-filter-p2', 'category_ids' => ['$catB.id$']], as: 'p2'),
    DataFixture(
        ProductFixture::class,
        ['sku' => 'or-filter-p3', 'category_ids' => ['$catA.id$', '$catB.id$']],
        as: 'p3'
    ),
    DataFixture(ProductFixture::class, ['sku' => 'or-filter-q?x'], as: 'question'),
    DataFixture(ProductFixture::class, ['sku' => "or-filter-q'x"], as: 'quote'),
]
class FilterGroupOrConditionTest extends TestCase
{
    /**
     * @var DataFixtureStorage
     */
    private $fixtures;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var FilterBuilder
     */
    private $filterBuilder;

    /**
     * @var FilterGroupBuilder
     */
    private $filterGroupBuilder;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $this->productRepository = $objectManager->get(ProductRepositoryInterface::class);
        $this->filterBuilder = $objectManager->get(FilterBuilder::class);
        $this->filterGroupBuilder = $objectManager->get(FilterGroupBuilder::class);
        $this->searchCriteriaBuilder = $objectManager->get(SearchCriteriaBuilder::class);
    }

    public function testTwoCategoryFiltersInOneGroupReturnTheUnion(): void
    {
        $skus = $this->search([[
            $this->filter('category_id', $this->id('catA')),
            $this->filter('category_id', $this->id('catB')),
        ]]);

        $this->assertSame(['or-filter-p1', 'or-filter-p2', 'or-filter-p3'], $skus);
    }

    public function testCategoryFilterOrPlainFieldInOneGroupReturnsTheUnion(): void
    {
        $skus = $this->search([[
            $this->filter('category_id', $this->id('catB')),
            $this->filter('sku', 'or-filter-p1'),
        ]]);

        $this->assertSame(['or-filter-p1', 'or-filter-p2', 'or-filter-p3'], $skus);
    }

    public function testCategoryFilterOrEavAttributeInOneGroupReturnsTheUnion(): void
    {
        $skus = $this->search([[
            $this->filter('category_id', $this->id('catB')),
            $this->filter('name', $this->fixtures->get('p1')->getName()),
        ]]);

        $this->assertSame(['or-filter-p1', 'or-filter-p2', 'or-filter-p3'], $skus);
    }

    public function testQuestionMarkInPlainValueIsMatchedLiterally(): void
    {
        $skus = $this->search([[
            $this->filter('category_id', $this->id('catA')),
            $this->filter('sku', 'or-filter-q?x'),
        ]]);

        $this->assertSame(['or-filter-p1', 'or-filter-p3', 'or-filter-q?x'], $skus);
    }

    public function testSingleCategoryFilterIsUnchanged(): void
    {
        $this->assertSame(
            ['or-filter-p1', 'or-filter-p3'],
            $this->search([[$this->filter('category_id', $this->id('catA'))]])
        );
    }

    public function testCategoryInConditionIsUnchanged(): void
    {
        $skus = $this->search([[
            $this->filter('category_id', $this->id('catA') . ',' . $this->id('catB'), 'in'),
        ]]);

        $this->assertSame(['or-filter-p1', 'or-filter-p2', 'or-filter-p3'], $skus);
    }

    public function testSeparateGroupsStayAnded(): void
    {
        $skus = $this->search([
            [$this->filter('category_id', $this->id('catA'))],
            [$this->filter('category_id', $this->id('catB'))],
        ]);

        $this->assertSame(['or-filter-p3'], $skus);
    }

    public function testPlainFieldsInOneGroupAreStillOred(): void
    {
        $skus = $this->search([[
            $this->filter('sku', 'or-filter-p1'),
            $this->filter('sku', 'or-filter-p2'),
        ]]);

        $this->assertSame(['or-filter-p1', 'or-filter-p2'], $skus);
    }

    /**
     * @param array[] $groups list of filter lists, one per group
     * @return string[] sorted SKUs of the fixture products found
     */
    private function search(array $groups): array
    {
        $filterGroups = [];
        foreach ($groups as $filters) {
            $filterGroups[] = $this->filterGroupBuilder->setFilters($filters)->create();
        }
        $criteria = $this->searchCriteriaBuilder->create()->setFilterGroups($filterGroups);

        $skus = [];
        foreach ($this->productRepository->getList($criteria)->getItems() as $product) {
            if (str_starts_with($product->getSku(), 'or-filter-')) {
                $skus[] = $product->getSku();
            }
        }
        sort($skus);
        return $skus;
    }

    private function filter(string $field, string $value, string $conditionType = 'eq')
    {
        return $this->filterBuilder->setField($field)->setValue($value)->setConditionType($conditionType)->create();
    }

    private function id(string $fixture): string
    {
        return (string)$this->fixtures->get($fixture)->getId();
    }
}
