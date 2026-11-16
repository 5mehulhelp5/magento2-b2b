<?php
declare(strict_types=1);

namespace Tonyapps\B2b\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Tonyapps\B2b\Api\CompanyRepositoryInterface;
use Tonyapps\B2b\Api\Data\CompanyInterface;
use Tonyapps\B2b\Model\ResourceModel\Company as CompanyResource;

/**
 * Real-data preview of the company emails for Tonyapps_EmailDesigner (registered in etc/di.xml, duck-typed).
 * The variables come from CompanyEmail, the sender itself. Read-only.
 */
class EmailPreview
{
    /** Template => company status whose companies get that email; the new-company email goes to any. */
    private const STATUSES = [
        'tonyapps_b2b_email_approved_template' => CompanyInterface::STATUS_ACTIVE,
        'tonyapps_b2b_email_rejected_template' => CompanyInterface::STATUS_REJECTED,
    ];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CompanyRepositoryInterface $companies,
        private readonly CompanyEmail $companyEmail
    ) {
    }

    /** @return array<string, string> company ID => label, newest first */
    public function getRecords(string $templateId, int $limit): array
    {
        $status = self::STATUSES[$templateId] ?? null;
        $rows = $status === null ? [] : $this->fetch($limit, $status);
        // No company with that status yet: any company still shows the design with real names.
        $rows = $rows ?: $this->fetch($limit, null);

        $records = [];
        foreach ($rows as $row) {
            $records[(string)$row['entity_id']] = implode(' · ', array_filter([
                '#' . $row['entity_id'],
                (string)$row['name'],
                substr((string)$row['created_at'], 0, 10),
            ]));
        }
        return $records;
    }

    /** @return array<string, string> */
    public function getVariables(string $templateId, string $recordId): array
    {
        $company = $this->companies->getById((int)$recordId);
        return match ($templateId) {
            'tonyapps_b2b_email_new_company_template' => $this->companyEmail->getNewCompanyVariables($company),
            'tonyapps_b2b_email_approved_template' => $this->companyEmail->getDecisionVariables($company, true),
            'tonyapps_b2b_email_rejected_template' => $this->companyEmail->getDecisionVariables($company, false),
            default => throw new LocalizedException(__('No real-data preview for this email.')),
        };
    }

    /** The store the sender uses: companies are global, so the same for every company. */
    public function getStoreId(string $templateId, string $recordId): ?int
    {
        return $this->companyEmail->getStoreId();
    }

    /** @return array<int, array<string, mixed>> */
    private function fetch(int $limit, ?int $status): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(CompanyResource::TABLE), ['entity_id', 'name', 'created_at'])
            ->order('entity_id DESC')
            ->limit($limit);
        if ($status !== null) {
            $select->where('status = ?', $status);
        }
        return $connection->fetchAll($select);
    }
}
