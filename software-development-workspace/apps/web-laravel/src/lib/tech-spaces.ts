/**
 * Canonical technology space + subcategory catalogue.
 *
 * Used in vendor product and professional-certification forms to categorise
 * the areas of technology a vendor covers.
 */

export interface TechSubcategory {
  value: string;
  label: string;
}

export interface TechSpace {
  value: string;
  label: string;
  color: string; // CSS var or hex used for badge/card accents
  subcategories: TechSubcategory[];
}

export const TECH_SPACES: TechSpace[] = [
  {
    value: 'infrastructure',
    label: 'Infrastructure',
    color: '#3b82f6',
    subcategories: [
      { value: 'networking',       label: 'Networking' },
      { value: 'storage',          label: 'Storage' },
      { value: 'compute_servers',  label: 'Compute / Servers' },
      { value: 'virtualization',   label: 'Virtualization' },
      { value: 'hci',              label: 'Hyperconverged Infrastructure (HCI)' },
      { value: 'data_center',      label: 'Data Center' },
      { value: 'edge_computing',   label: 'Edge Computing' },
      { value: 'power_cooling',    label: 'Power & Cooling' },
    ],
  },
  {
    value: 'cybersecurity',
    label: 'Cybersecurity',
    color: '#ef4444',
    subcategories: [
      { value: 'edr',                      label: 'Endpoint Detection & Response (EDR)' },
      { value: 'network_security',         label: 'Network Security' },
      { value: 'iam',                      label: 'Identity & Access Management (IAM)' },
      { value: 'siem',                     label: 'SIEM / SOC' },
      { value: 'vulnerability_management', label: 'Vulnerability Management' },
      { value: 'cloud_security',           label: 'Cloud Security (CSPM / CWPP)' },
      { value: 'zero_trust',               label: 'Zero Trust Architecture' },
      { value: 'dlp',                      label: 'Data Loss Prevention (DLP)' },
      { value: 'email_security',           label: 'Email Security' },
      { value: 'threat_intelligence',      label: 'Threat Intelligence' },
      { value: 'pam',                      label: 'Privileged Access Management (PAM)' },
      { value: 'appsec',                   label: 'Application Security (AppSec)' },
    ],
  },
  {
    value: 'databases',
    label: 'Databases',
    color: '#8b5cf6',
    subcategories: [
      { value: 'relational',       label: 'Relational (SQL)' },
      { value: 'nosql',            label: 'NoSQL / Document Stores' },
      { value: 'in_memory',        label: 'In-Memory Databases' },
      { value: 'time_series',      label: 'Time-Series Databases' },
      { value: 'graph',            label: 'Graph Databases' },
      { value: 'data_warehousing', label: 'Data Warehousing' },
      { value: 'db_management',    label: 'Database Management & Monitoring' },
    ],
  },
  {
    value: 'public_cloud',
    label: 'Public Cloud',
    color: '#f59e0b',
    subcategories: [
      { value: 'aws',             label: 'Amazon Web Services (AWS)' },
      { value: 'azure',           label: 'Microsoft Azure' },
      { value: 'gcp',             label: 'Google Cloud Platform (GCP)' },
      { value: 'multi_cloud',     label: 'Multi-Cloud Management' },
      { value: 'cloud_migration', label: 'Cloud Migration & Adoption' },
      { value: 'cloud_native',    label: 'Cloud-Native Development' },
      { value: 'serverless',      label: 'Serverless Computing' },
      { value: 'finops',          label: 'FinOps & Cost Management' },
    ],
  },
  {
    value: 'hybrid_private_cloud',
    label: 'Private / Hybrid Cloud',
    color: '#0ea5e9',
    subcategories: [
      { value: 'private_cloud',  label: 'Private Cloud Platforms' },
      { value: 'hybrid_cloud',   label: 'Hybrid Cloud Management' },
      { value: 'draas',          label: 'Disaster Recovery as a Service (DRaaS)' },
    ],
  },
  {
    value: 'devops',
    label: 'Software Development & DevOps',
    color: '#10b981',
    subcategories: [
      { value: 'cicd',            label: 'CI/CD Pipelines' },
      { value: 'containers',      label: 'Containers & Kubernetes' },
      { value: 'source_control',  label: 'Source Control & Collaboration' },
      { value: 'apm',             label: 'Application Performance Monitoring (APM)' },
      { value: 'test_automation', label: 'Test Automation' },
      { value: 'low_code',        label: 'Low-Code / No-Code Platforms' },
    ],
  },
  {
    value: 'data_analytics',
    label: 'Data & Analytics',
    color: '#f97316',
    subcategories: [
      { value: 'bi',              label: 'Business Intelligence (BI)' },
      { value: 'big_data',        label: 'Big Data Platforms' },
      { value: 'etl',             label: 'Data Integration & ETL' },
      { value: 'ai_ml',           label: 'Artificial Intelligence & ML' },
      { value: 'data_governance', label: 'Data Governance & Quality' },
      { value: 'data_viz',        label: 'Data Visualization' },
      { value: 'mdm',             label: 'Master Data Management (MDM)' },
    ],
  },
  {
    value: 'collaboration',
    label: 'Collaboration & Productivity',
    color: '#06b6d4',
    subcategories: [
      { value: 'ucaas',               label: 'Unified Communications (UCaaS)' },
      { value: 'email_messaging',     label: 'Email & Messaging' },
      { value: 'video_conferencing',  label: 'Video Conferencing' },
      { value: 'document_management', label: 'Document & Content Management' },
      { value: 'project_management',  label: 'Project & Work Management' },
    ],
  },
  {
    value: 'erp_business_apps',
    label: 'ERP & Business Applications',
    color: '#6366f1',
    subcategories: [
      { value: 'erp',           label: 'Enterprise Resource Planning (ERP)' },
      { value: 'crm',           label: 'Customer Relationship Management (CRM)' },
      { value: 'hcm',           label: 'Human Capital Management (HCM)' },
      { value: 'finance',       label: 'Finance & Accounting' },
      { value: 'supply_chain',  label: 'Supply Chain Management' },
      { value: 'field_service', label: 'Field Service Management' },
    ],
  },
  {
    value: 'iot',
    label: 'IoT & Embedded Systems',
    color: '#84cc16',
    subcategories: [
      { value: 'industrial_iot',  label: 'Industrial IoT (IIoT)' },
      { value: 'smart_devices',   label: 'Smart Devices & Sensors' },
      { value: 'edge_ai',         label: 'Edge AI' },
      { value: 'embedded_software', label: 'Embedded Software' },
    ],
  },
  {
    value: 'managed_services',
    label: 'Managed Services',
    color: '#ec4899',
    subcategories: [
      { value: 'mssp',          label: 'Managed Security Services (MSSP)' },
      { value: 'msp',           label: 'Managed IT Services (MSP)' },
      { value: 'noc_soc',       label: 'NOC / SOC Services' },
      { value: 'help_desk',     label: 'Help Desk & IT Support' },
      { value: 'managed_cloud', label: 'Managed Cloud Services' },
    ],
  },
];

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Get display label for a space value */
export function spaceLabel(value: string): string {
  return TECH_SPACES.find(s => s.value === value)?.label ?? value;
}

/** Get display label for a subcategory value (searched across all spaces) */
export function subcategoryLabel(subcategoryValue: string): string {
  for (const space of TECH_SPACES) {
    const sub = space.subcategories.find(s => s.value === subcategoryValue);
    if (sub) return sub.label;
  }
  return subcategoryValue;
}

/** Get the subcategories for a given space value */
export function subcategoriesFor(spaceValue: string): TechSubcategory[] {
  return TECH_SPACES.find(s => s.value === spaceValue)?.subcategories ?? [];
}

/** Get accent colour for a space value */
export function spaceColor(value: string): string {
  return TECH_SPACES.find(s => s.value === value)?.color ?? '#6b7280';
}
