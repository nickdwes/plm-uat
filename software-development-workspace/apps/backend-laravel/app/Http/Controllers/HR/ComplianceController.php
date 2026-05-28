<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\OrgComplianceDocument;
use App\Models\Organization;
use App\Services\SharePointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ComplianceController extends Controller
{
    private const CATALOGUE = [
        ['key' => 'business_registration_cert',  'segment' => 'Core Registration',       'label' => 'Business Registration Certificate',              'perpetual' => false,  'guide' => "Register and renew with the Business Registration Service (BRS) Kenya.\nPortal: https://www.businessregistrationservice.go.ke\nRequirements: BN/1 form, ID copies of all proprietors/partners, prescribed fee (KES 950 for 5 years).\nRenewal: Every 5 years at the BRS portal or any Huduma Centre."],
        ['key' => 'certificate_of_incorporation', 'segment' => 'Core Registration',       'label' => 'Certificate of Incorporation',                    'perpetual' => true,   'guide' => "Issued once by the Registrar of Companies under the Companies Act, 2015 (Cap 486).\nPortal: https://www.businessregistrationservice.go.ke\nDoes not expire. Keep a certified copy. If lost, apply for a certified copy from BRS (fee applies)."],
        ['key' => 'cr12',                         'segment' => 'Core Registration',       'label' => 'CR12 – Company Annual Return / Extract',           'perpetual' => false,  'guide' => "CR12 is a company search extract confirming current directors and shareholding.\nPortal: https://www.businessregistrationservice.go.ke  Fee: KES 650.\nValidity: ~3 months. File Annual Returns annually to avoid penalties."],
        ['key' => 'memorandum_articles',          'segment' => 'Core Registration',       'label' => 'Memorandum and Articles of Association',           'perpetual' => true,   'guide' => "Defines company objects and governance rules.\nAmendments must be filed with BRS within 14 days of a special resolution.\nKeep the latest certified copy at the registered office."],
        ['key' => 'kra_pin_cert',                 'segment' => 'Tax Compliance',          'label' => 'KRA PIN Certificate',                             'perpetual' => true,   'guide' => "Confirms tax registration with Kenya Revenue Authority.\nPortal: https://itax.kra.go.ke\nDoes not expire but must remain active — file all returns to keep it in good standing."],
        ['key' => 'kra_tax_compliance_cert',      'segment' => 'Tax Compliance',          'label' => 'KRA Tax Compliance Certificate (TCC)',             'perpetual' => false,  'guide' => "Proves all KRA obligations are up to date.\nPortal: https://itax.kra.go.ke → Certificates → Apply for TCC\nValidity: 12 months.\nRequirements: All returns filed (VAT, PAYE, Corporate Tax). Pending liabilities settled or under a payment plan."],
        ['key' => 'vat_registration_cert',        'segment' => 'Tax Compliance',          'label' => 'VAT Registration Certificate',                    'perpetual' => true,   'guide' => "Mandatory when taxable turnover exceeds KES 5 million/year.\nPortal: https://itax.kra.go.ke → Registration.\nMonthly: File VAT returns by the 20th.\nPenalty for late filing: KES 10,000/month.\nReference: VAT Act, Cap 476 Kenya."],
        ['key' => 'paye_registration',            'segment' => 'Tax Compliance',          'label' => 'PAYE Registration & Compliance',                  'perpetual' => false,  'guide' => "Deduct PAYE from all employees above the tax-free threshold.\nPortal: https://itax.kra.go.ke\nFiling: Monthly returns due by the 9th of the following month.\nPenalty: 25% of tax due for late payment.\nReference: Income Tax Act, Cap 470 Kenya."],
        ['key' => 'county_business_permit',       'segment' => 'County Licensing',        'label' => 'Unified Business Permit (County)',                 'perpetual' => false,  'guide' => "Issued by the relevant County Government.\nFor Nairobi: https://eservices.nairobi.go.ke\nValidity: 1 year (Jan–Dec). Renew by 31 March annually.\nRequired: KRA PIN, cert of incorporation, lease agreement, previous permit.\nPenalty: 200% surcharge for late renewal."],
        ['key' => 'fire_safety_cert',             'segment' => 'County Licensing',        'label' => 'Fire Safety Certificate',                         'perpetual' => false,  'guide' => "Issued by the County Fire Department after physical inspection.\nValidity: Annual.\nRequirements: Fire extinguishers, clear evacuation routes, smoke detectors.\nReference: OSHA, 2007; Fire Risk Reduction Rules."],
        ['key' => 'public_health_cert',           'segment' => 'County Licensing',        'label' => 'Public Health Certificate',                       'perpetual' => false,  'guide' => "Issued by the County Public Health Officer after inspection of sanitation standards.\nValidity: Annual. Apply at the County Public Health Office.\nReference: Public Health Act, Cap 242 Kenya."],
        ['key' => 'nita_cert',                    'segment' => 'Employment & Training',   'label' => 'NITA Training Levy Compliance Certificate',        'perpetual' => false,  'guide' => "Employers with 5+ employees must pay training levy to NITA.\nPortal: https://www.nita.go.ke  Levy: KES 50/employee/month. Filing: Quarterly.\nBenefit: Claim 75% of levy as reimbursement for approved trainings.\nReference: Industrial Training Act, Cap 237 Kenya."],
        ['key' => 'nssf_cert',                    'segment' => 'Employment & Training',   'label' => 'NSSF Employer Registration Certificate',           'perpetual' => true,   'guide' => "Mandatory registration for all employers.\nPortal: https://www.nssf.or.ke\nContributions: Tier 1 (KES 420/month) + Tier 2 (6% of pensionable pay) under NSSF Act 2013.\nRemit by the 9th of the following month."],
        ['key' => 'nhif_sha_cert',                'segment' => 'Employment & Training',   'label' => 'SHA / NHIF Employer Registration Certificate',     'perpetual' => true,   'guide' => "Register with the Social Health Authority (SHA), formerly NHIF.\nPortal: https://sha.go.ke\nDeduct employee portion and remit combined by the 9th monthly.\nReference: Social Health Insurance Act, 2023 Kenya."],
        ['key' => 'employment_contracts',         'segment' => 'Employment & Training',   'label' => 'Employment Contracts (Employment Act 2007)',       'perpetual' => true,   'guide' => "All employees on contracts exceeding 3 months require a written, signed contract.\nMinimum content: names, job title, start date, pay, hours, leave entitlement, notice period.\nReference: Employment Act, 2007 (Cap 226), Section 9 Kenya."],
        ['key' => 'osha_cert',                    'segment' => 'Workplace Safety',        'label' => 'OSHA Workplace Registration & Compliance Cert',    'perpetual' => false,  'guide' => "Register your workplace with the Directorate of Occupational Safety and Health Services (DOSH).\nPortal: https://www.dosh.go.ke  Validity: Annual.\nRequired: safety policy, H&S committee (20+ employees), first-aid kits, risk assessments.\nReference: OSHA, 2007 Kenya."],
        ['key' => 'workplace_safety_audit',       'segment' => 'Workplace Safety',        'label' => 'Workplace Safety Audit Report',                    'perpetual' => false,  'guide' => "Annual safety audits required for workplaces with 20+ employees.\nAudit by a Government-approved OSHA auditor. Submit report to DOSH county office.\nRetain the last 3 years of reports.\nReference: OSHA, 2007 – Section 11 Kenya."],
        ['key' => 'incident_records',             'segment' => 'Workplace Safety',        'label' => 'Incident & Accident Records (OSHA)',               'perpetual' => true,   'guide' => "Record all workplace injuries, dangerous occurrences, and occupational diseases.\nFatal accidents: report to DOSH within 24 hours (Form DOSH 1).\nNon-fatal: within 7 days (Form DOSH 2). Retain records 5+ years.\nReference: OSHA, 2007 – Part V Kenya."],
        ['key' => 'wiba_cert',                    'segment' => 'Workplace Safety',        'label' => 'WIBA – Work Injury Benefits Act Compliance',       'perpetual' => false,  'guide' => "Insure all employees against work-related injuries with an approved WIBA insurer.\nPremium based on payroll and risk type. Notify insurer within 24 hours of injury.\nReference: Work Injury Benefits Act (WIBA), 2007 Kenya."],
        ['key' => 'odpc_registration',            'segment' => 'Data Protection',         'label' => 'ODPC Data Controller / Processor Registration',    'perpetual' => false,  'guide' => "Register as a Data Controller or Processor with ODPC.\nPortal: https://www.odpc.go.ke  Validity: 3 years (renewable). Fee: KES 15,000–100,000.\nRequirements: data protection policy, DPO appointment if required.\nReference: Data Protection Act, 2019 Kenya – Section 15."],
        ['key' => 'dpa_compliance',               'segment' => 'Data Protection',         'label' => 'DPA 2019 Compliance Record',                       'perpetual' => true,   'guide' => "Maintain records of all processing activities.\nRecord: lawful basis, data categories, retention periods, third-party sharing.\nAppoint a DPO if processing large-scale special category data.\nReference: Data Protection Act, 2019; ODPC Guidelines Kenya."],
        ['key' => 'dpia_records',                 'segment' => 'Data Protection',         'label' => 'Data Protection Impact Assessment (DPIA)',          'perpetual' => false,  'guide' => "Conduct a DPIA before high-risk processing activities (profiling, biometric data, employee surveillance).\nDocument: data flows, risks, mitigation, residual risks.\nReference: Data Protection (General) Regulations, 2021 Kenya – Regulation 31."],
        ['key' => 'ca_icts_licence',              'segment' => 'ICT & Sector Licensing',  'label' => 'Communications Authority ICTS Licence',             'perpetual' => false,  'guide' => "ICT service providers must be licensed by Communications Authority (CA).\nPortal: https://www.ca.go.ke → Licensing → ICTS\nValidity: Up to 25 years. Annual regulatory fee applies.\nReference: Kenya Information and Communications Act, Cap 411A."],
        ['key' => 'ca_type_approval',             'segment' => 'ICT & Sector Licensing',  'label' => 'CA Type Approval',                                 'perpetual' => false,  'guide' => "All ICT equipment connected to public networks must be type-approved by CA.\nPortal: https://www.ca.go.ke → Type Approval  Validity: 2 years.\nRequired for: radios, mobile devices, network equipment.\nReference: Kenya Information and Communications (Radio Apparatus) Regulations."],
        ['key' => 'icta_certification',           'segment' => 'ICT & Sector Licensing',  'label' => 'ICT Authority Kenya Certification / Accreditation', 'perpetual' => false,  'guide' => "ICTA accredits ICT trainers, assessors, and certifies ICT professionals and suppliers.\nPortal: https://www.icta.go.ke\nRequired for: government ICT supply, training providers, e-government operators.\nReference: ICT Authority Act, 2013 Kenya."],
        ['key' => 'iso_27001',                    'segment' => 'Standards & Certification','label' => 'ISO/IEC 27001 – Information Security Management',   'perpetual' => false,  'guide' => "Certifies an Information Security Management System (ISMS).\nValidity: 3 years with annual surveillance audits.\nCertifying bodies: KEBS, BSI, Bureau Veritas, SGS.\nProcess: Gap assessment → ISMS implementation → Stage 1 & 2 audit → certification.\nReference: ISO/IEC 27001:2022."],
        ['key' => 'iso_9001',                     'segment' => 'Standards & Certification','label' => 'ISO 9001 – Quality Management System',              'perpetual' => false,  'guide' => "Certifies a Quality Management System (QMS).\nValidity: 3 years with annual surveillance audits.\nCertifying bodies in Kenya: KEBS, Bureau Veritas, SGS, TÜV.\nReference: ISO 9001:2015."],
        ['key' => 'kebs_standardization_mark',    'segment' => 'Standards & Certification','label' => 'KEBS Standardization Mark Licence',                 'perpetual' => false,  'guide' => "Products manufactured or imported for sale may require the KEBS Diamond Mark.\nPortal: https://www.kebs.org → Product Certification  Validity: Annual.\nReference: Standards Act, Cap 496 Kenya."],
    ];

    private function orgId(): int
    {
        return (int) app('current.organization_id');
    }

    private function seed(int $orgId): void
    {
        $existing = OrgComplianceDocument::where('organization_id', $orgId)
            ->pluck('document_key')->flip()->toArray();

        $now      = now();
        $defaults = OrgComplianceDocument::defaultThresholds();
        $rows     = [];

        foreach (self::CATALOGUE as $item) {
            if (isset($existing[$item['key']])) continue;
            $rows[] = [
                'organization_id'     => $orgId,
                'document_key'        => $item['key'],
                'segment'             => $item['segment'],
                'document_label'      => $item['label'],
                'is_perpetual'        => $item['perpetual'],
                'renewal_guide'       => $item['guide'] ?? null,
                'reminder_thresholds' => json_encode($defaults),
                'reminder_active'     => false,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        if ($rows) OrgComplianceDocument::insert($rows);
    }

    private function fmt(OrgComplianceDocument $d): array
    {
        return [
            'id'                    => $d->id,
            'document_key'          => $d->document_key,
            'segment'               => $d->segment,
            'document_label'        => $d->document_label,
            'is_perpetual'          => $d->is_perpetual,
            'file_url'              => $d->file_url,
            'file_name'             => $d->file_name,
            'issue_date'            => $d->issue_date?->toDateString(),
            'expiry_date'           => $d->expiry_date?->toDateString(),
            'anniversary_date'      => $d->anniversary_date?->toDateString(),
            'notes'                 => $d->notes,
            'owner_name'            => $d->owner_name,
            'owner_email'           => $d->owner_email,
            'status_override'       => $d->status_override,
            'renewal_guide'         => $d->renewal_guide,
            'reminder_days_before'  => $d->reminder_days_before,
            'reminder_recipients'   => $d->reminder_recipients,
            'reminder_active'       => $d->reminder_active,
            'reminder_thresholds'   => $d->reminder_thresholds ?? OrgComplianceDocument::defaultThresholds(),
            'last_reminder_sent_at' => $d->last_reminder_sent_at?->toIso8601String(),
            'audit_trail'           => $d->audit_trail ?? [],
            'status'                => $d->status,
            'days_until_expiry'     => $d->days_until_expiry,
        ];
    }

    private function summary(\Illuminate\Database\Eloquent\Collection $docs): array
    {
        $c = ['total'=>0,'not_uploaded'=>0,'valid'=>0,'expiring'=>0,'expired'=>0,'under_review'=>0,'pending_renewal'=>0];
        $c['total'] = $docs->count();
        foreach ($docs as $d) {
            $s = $d->status;
            if ($s === 'Not Uploaded')      $c['not_uploaded']++;
            elseif ($s === 'Valid')         $c['valid']++;
            elseif (str_starts_with($s,'Expiring')) $c['expiring']++;
            elseif ($s === 'Expired')       $c['expired']++;
            elseif ($s === 'Under Review')  $c['under_review']++;
            elseif ($s === 'Pending Renewal') $c['pending_renewal']++;
        }
        return $c;
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $orgId = $this->orgId();
        $this->seed($orgId);

        $keyOrder = array_column(self::CATALOGUE, 'key');
        $docs     = OrgComplianceDocument::where('organization_id', $orgId)->get();
        $sorted   = $docs->sortBy(fn($d) => array_search($d->document_key, $keyOrder));

        return response()->json([
            'data'    => $sorted->map(fn($d) => $this->fmt($d))->values(),
            'summary' => $this->summary($docs),
        ]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $doc = OrgComplianceDocument::where('organization_id', $this->orgId())
            ->where('document_key', $key)->firstOrFail();

        $v = $request->validate([
            'issue_date'      => 'nullable|date',
            'expiry_date'     => 'nullable|date',
            'anniversary_date'=> 'nullable|date',
            'notes'           => 'nullable|string|max:3000',
            'owner_name'      => 'nullable|string|max:150',
            'owner_email'     => 'nullable|email|max:150',
            'status_override' => 'nullable|string|in:Under Review,Pending Renewal,',
        ]);

        $doc->update($v);
        $u = $request->user();
        $doc->appendAudit('updated', $u->name ?? $u->email, 'Document details updated.');
        return response()->json($this->fmt($doc->fresh()));
    }

    public function updateGuide(Request $request, string $key): JsonResponse
    {
        $doc = OrgComplianceDocument::where('organization_id', $this->orgId())
            ->where('document_key', $key)->firstOrFail();

        $v = $request->validate(['renewal_guide' => 'nullable|string|max:8000']);
        $doc->update($v);
        $u = $request->user();
        $doc->appendAudit('guide_updated', $u->name ?? $u->email, 'Renewal guide updated.');
        return response()->json($this->fmt($doc->fresh()));
    }

    public function updateReminder(Request $request, string $key): JsonResponse
    {
        $doc = OrgComplianceDocument::where('organization_id', $this->orgId())
            ->where('document_key', $key)->firstOrFail();

        $v = $request->validate([
            'reminder_days_before'  => 'nullable|integer|min:1|max:365',
            'reminder_recipients'   => 'nullable|string|max:2000',
            'reminder_active'       => 'required|boolean',
            'reminder_thresholds'   => 'nullable|array',
            'reminder_thresholds.*' => 'integer|min:1|max:365',
        ]);

        $doc->update($v);
        return response()->json($this->fmt($doc->fresh()));
    }

    public function uploadFile(Request $request, string $key): JsonResponse
    {
        $doc = OrgComplianceDocument::where('organization_id', $this->orgId())
            ->where('document_key', $key)->firstOrFail();

        $request->validate([
            'file' => 'required|file|max:20480|mimes:pdf,png,jpg,jpeg,webp,doc,docx',
        ]);

        // Delete previous SharePoint file if one exists
        if ($doc->sharepoint_item_id) {
            try {
                app(SharePointService::class)->delete($doc->sharepoint_item_id);
            } catch (\Exception) {
                // Ignore deletion failures — proceed with the new upload
            }
        }

        $file     = $request->file('file');
        $ext      = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $filename = SharePointService::buildFilename($doc->document_label, $ext);

        $result = app(SharePointService::class)->upload(
            $file->getRealPath(),
            $filename,
            $mimeType
        );

        $doc->update([
            'file_url'           => $result['web_url'],
            'file_name'          => $filename,
            'file_disk_path'     => null,
            'sharepoint_item_id' => $result['item_id'],
        ]);

        $u = $request->user();
        $doc->appendAudit('file_uploaded', $u->name ?? $u->email, "Uploaded to SharePoint: {$filename}");
        return response()->json($this->fmt($doc->fresh()));
    }

    public function deleteFile(Request $request, string $key): JsonResponse
    {
        $doc = OrgComplianceDocument::where('organization_id', $this->orgId())
            ->where('document_key', $key)->firstOrFail();

        // Delete from SharePoint
        if ($doc->sharepoint_item_id) {
            try {
                app(SharePointService::class)->delete($doc->sharepoint_item_id);
            } catch (\Exception) {
                // Ignore — clean up DB record regardless
            }
        }

        // Clean up any legacy local file
        if ($doc->file_disk_path && Storage::disk('public')->exists($doc->file_disk_path)) {
            Storage::disk('public')->delete($doc->file_disk_path);
        }

        $doc->update(['file_url' => null, 'file_name' => null, 'file_disk_path' => null, 'sharepoint_item_id' => null]);
        $u = $request->user();
        $doc->appendAudit('file_deleted', $u->name ?? $u->email);
        return response()->json($this->fmt($doc->fresh()));
    }
}
