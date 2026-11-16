<?php
declare(strict_types=1);

namespace Tonyapps\B2b\Test\Unit\Block\Company;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Theme\Block\Html\Pager;
use PHPUnit\Framework\TestCase;
use Tonyapps\B2b\Block\Company\Orders;
use Tonyapps\B2b\Model\CompanyContext;

/**
 * Hyvä's layout prepares the Company Orders block twice; the second pass reuses the pager instead of creating a
 * second block with the same name, which threw and turned the page into a 500.
 */
class OrdersPagerTest extends TestCase
{
    public function testASecondLayoutPassReusesThePager(): void
    {
        $collection = $this->createMock(Collection::class);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $context = $this->createMock(CompanyContext::class);
        $context->method('getCompany')->willReturn(null);
        $block = (new ObjectManager($this))->getObject(Orders::class, [
            'companyContext' => $context,
            'orderCollectionFactory' => $factory,
        ]);

        $pager = $this->createMock(Pager::class);
        $pager->method('setLimit')->willReturnSelf();
        $pager->expects(self::once())->method('setCollection')->with($collection)->willReturnSelf();
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->willReturnMap([['tonyapps_b2b.company.orders.pager', $pager]]);
        $layout->expects(self::never())->method('createBlock');

        $block->setLayout($layout);
    }
}
