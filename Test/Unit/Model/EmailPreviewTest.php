<?php
declare(strict_types=1);

namespace Tonyapps\B2b\Test\Unit\Model;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Customer\Api\CustomerNameGenerationInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\Url;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tonyapps\B2b\Api\CompanyRepositoryInterface;
use Tonyapps\B2b\Model\Company;
use Tonyapps\B2b\Model\CompanyEmail;
use Tonyapps\B2b\Model\Config;
use Tonyapps\B2b\Model\EmailPreview;

class EmailPreviewTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function emails(): array
    {
        return [
            'new company' => ['tonyapps_b2b_email_new_company_template', 'sendNewCompanyNotification'],
            'approved' => ['tonyapps_b2b_email_approved_template', 'sendApproved'],
            'rejected' => ['tonyapps_b2b_email_rejected_template', 'sendRejected'],
        ];
    }

    /**
     * @dataProvider emails
     */
    public function testPreviewHasTheVariablesTheSenderSendsAndSendsNothing(string $templateId, string $send): void
    {
        $company = $this->createMock(Company::class);
        $company->method('getId')->willReturn(4);
        $company->method('getName')->willReturn('Acme');
        $company->method('getData')->willReturn('buy@acme.test');
        $company->method('getAdminCustomerId')->willReturn(3);

        $sent = null;
        $this->companyEmail($this->capturingBuilder($sent))->$send($company);
        self::assertNotEmpty($sent);

        $repository = $this->createMock(CompanyRepositoryInterface::class);
        $repository->method('getById')->with(4)->willReturn($company);
        $repository->expects(self::never())->method('save');
        $silent = $this->createMock(TransportBuilder::class);
        $silent->expects(self::never())->method('setTemplateVars');
        $silent->expects(self::never())->method('getTransport');

        $preview = new EmailPreview(
            $this->createMock(ResourceConnection::class),
            $repository,
            $this->companyEmail($silent)
        );

        self::assertSame($sent, $preview->getVariables($templateId, '4'));
        // The store the sender sends from.
        self::assertSame(1, $preview->getStoreId($templateId, '4'));
    }

    public function testApprovedEmailOffersApprovedCompaniesAndFallsBackToAny(): void
    {
        $wheres = [];
        $select = $this->createMock(Select::class);
        foreach (['from', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('where')->willReturnCallback(function (...$args) use (&$wheres, $select) {
            $wheres[] = array_slice($args, 0, 2);
            return $select;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [],
            [['entity_id' => '9', 'name' => 'Acme', 'created_at' => '2026-11-10 08:00:00']]
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $records = (new EmailPreview(
            $resource,
            $this->createMock(CompanyRepositoryInterface::class),
            $this->createMock(CompanyEmail::class)
        ))->getRecords('tonyapps_b2b_email_approved_template', 20);

        self::assertSame([['status = ?', 1]], $wheres);
        self::assertSame(['9' => '#9 · Acme · 2026-11-10'], $records);
    }

    private function capturingBuilder(?array &$vars): TransportBuilder
    {
        $builder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('setTemplateVars')->willReturnCallback(
            function (array $templateVars) use (&$vars, $builder): TransportBuilder {
                $vars = $templateVars;
                return $builder;
            }
        );
        $builder->method('getTransport')->willReturn($this->createMock(TransportInterface::class));
        return $builder;
    }

    private function companyEmail(TransportBuilder $builder): CompanyEmail
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getConfig')->willReturn('owner@example.com');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createMock(Config::class);
        foreach (['getNewCompanyTemplate', 'getApprovedTemplate', 'getRejectedTemplate'] as $method) {
            $config->method($method)->willReturn('template');
        }
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('ada@acme.test');
        $customers = $this->createMock(CustomerRepositoryInterface::class);
        $customers->method('getById')->with(3)->willReturn($customer);
        $names = $this->createMock(CustomerNameGenerationInterface::class);
        $names->method('getCustomerName')->willReturn('Ada Admin');
        $backendUrl = $this->createMock(BackendUrlInterface::class);
        $backendUrl->method('getUrl')->willReturn('https://shop.test/admin/company/4/');
        $frontendUrl = $this->createMock(Url::class);
        $frontendUrl->method('setScope')->willReturnSelf();
        $frontendUrl->method('getUrl')->willReturn('https://shop.test/b2b/company/');

        return new CompanyEmail(
            $builder,
            $storeManager,
            $this->createMock(StateInterface::class),
            $config,
            $customers,
            $names,
            $backendUrl,
            $this->createMock(LoggerInterface::class),
            $frontendUrl
        );
    }
}
