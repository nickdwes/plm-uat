<template>
  <div class="view">

    <!-- ── Loading state ───────────────────────────────────────────────────── -->
    <div v-if="!vendor && vendorLoading" style="padding:var(--space-10);text-align:center;color:var(--color-text-muted)">
      Loading vendor profile…
    </div>

    <template v-if="vendor">

      <!-- ── Header ──────────────────────────────────────────────────────────── -->
      <div class="profile-header">
        <div class="profile-header-left">
          <button class="btn btn-ghost btn-sm" style="margin-bottom:var(--space-2)" @click="router.push({ name: 'vendors' })">
            ← Back to Vendors
          </button>
          <h2 style="margin:0;display:flex;align-items:center;gap:var(--space-3)">
            {{ vendor.name }}
            <span class="badge" :class="statusBadge(vendor.onboarding_status)">
              {{ statusLabel(vendor.onboarding_status) }}
            </span>
          </h2>
          <p style="margin:var(--space-1) 0 0;color:var(--color-text-muted);font-size:var(--font-size-sm)">
            <span v-if="vendor.vendor_number">{{ vendor.vendor_number }} ·</span>
            {{ typeLabel(vendor.vendor_type) }}
            <span v-if="vendor.category"> · {{ vendor.category }}</span>
          </p>
        </div>
        <div style="display:flex;gap:var(--space-3)">
          <button class="btn btn-secondary btn-sm" @click="openEditVendor">Edit Profile</button>
        </div>
      </div>

      <!-- ── Metrics row ──────────────────────────────────────────────────────── -->
      <div class="metrics-grid" style="grid-template-columns:repeat(4,1fr)">
        <div class="metric-card">
          <div class="metric-label">Products & Solutions</div>
          <div class="metric-value">{{ products.length }}</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">Incentives & NFRs</div>
          <div class="metric-value">{{ incentives.length }}</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">Certifications</div>
          <div class="metric-value">{{ certs.length }}</div>
        </div>
        <div class="metric-card">
          <div class="metric-label">Tech Spaces Covered</div>
          <div class="metric-value">{{ techCoverage.length }}</div>
        </div>
      </div>

      <!-- ── Tabs ──────────────────────────────────────────────────────────────── -->
      <div class="tab-nav">
        <button
          v-for="tab in TABS"
          :key="tab.id"
          class="tab-btn"
          :class="{ active: activeTab === tab.id }"
          @click="switchTab(tab.id)"
        >{{ tab.label }}</button>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <!-- TAB: Overview                                                          -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'overview'" class="tab-content">
        <div class="overview-grid">
          <!-- Company -->
          <div class="card">
            <div class="card-section-title">Company Details</div>
            <div class="detail-grid">
              <div class="detail-item"><span class="detail-key">Legal Name</span><span class="detail-val">{{ vendor.name }}</span></div>
              <div class="detail-item"><span class="detail-key">Vendor Number</span><span class="detail-val">{{ vendor.vendor_number ?? '—' }}</span></div>
              <div class="detail-item"><span class="detail-key">Registration No.</span><span class="detail-val">{{ vendor.registration_number ?? '—' }}</span></div>
              <div class="detail-item"><span class="detail-key">Tax ID / KRA PIN</span><span class="detail-val">{{ vendor.tax_id ?? '—' }}</span></div>
              <div class="detail-item"><span class="detail-key">Vendor Type</span><span class="detail-val">{{ typeLabel(vendor.vendor_type) }}</span></div>
              <div class="detail-item"><span class="detail-key">Category</span><span class="detail-val">{{ vendor.category ?? '—' }}</span></div>
              <div v-if="vendor.description" class="detail-item detail-item--full">
                <span class="detail-key">Description</span>
                <span class="detail-val" style="white-space:pre-wrap">{{ vendor.description }}</span>
              </div>
            </div>
          </div>
          <!-- Contact -->
          <div class="card">
            <div class="card-section-title">Primary Contact</div>
            <div class="detail-grid">
              <div class="detail-item"><span class="detail-key">Contact Name</span><span class="detail-val">{{ vendor.primary_contact_name ?? '—' }}</span></div>
              <div class="detail-item"><span class="detail-key">Designation</span><span class="detail-val">{{ vendor.primary_contact_designation ?? '—' }}</span></div>
              <div class="detail-item">
                <span class="detail-key">Email</span>
                <span class="detail-val">
                  <a v-if="vendor.contact_email" :href="'mailto:' + vendor.contact_email" style="color:var(--color-brand-base)">{{ vendor.contact_email }}</a>
                  <span v-else>—</span>
                </span>
              </div>
              <div class="detail-item"><span class="detail-key">Phone</span><span class="detail-val">{{ vendor.contact_phone ?? '—' }}</span></div>
              <div class="detail-item detail-item--full">
                <span class="detail-key">Website</span>
                <span class="detail-val">
                  <a v-if="vendor.website_url" :href="vendor.website_url" target="_blank" rel="noopener" style="color:var(--color-brand-base)">{{ vendor.website_url }}</a>
                  <span v-else>—</span>
                </span>
              </div>
              <div class="detail-item detail-item--full">
                <span class="detail-key">Physical Address</span>
                <span class="detail-val">{{ [vendor.physical_address, vendor.city, vendor.country].filter(Boolean).join(', ') || '—' }}</span>
              </div>
            </div>
          </div>
          <!-- Status -->
          <div class="card" style="grid-column:1/-1">
            <div class="card-section-title">Onboarding Status</div>
            <div class="detail-grid">
              <div class="detail-item">
                <span class="detail-key">Status</span>
                <span class="detail-val"><span class="badge" :class="statusBadge(vendor.onboarding_status)">{{ statusLabel(vendor.onboarding_status) }}</span></span>
              </div>
              <div class="detail-item"><span class="detail-key">Active</span><span class="detail-val">{{ vendor.is_active ? 'Yes' : 'No' }}</span></div>
              <div v-if="vendor.approved_at" class="detail-item"><span class="detail-key">Approved At</span><span class="detail-val">{{ fmtDate(vendor.approved_at) }}</span></div>
              <div v-if="vendor.rejection_reason" class="detail-item detail-item--full">
                <span class="detail-key">Reason</span>
                <span class="detail-val" style="color:var(--color-error)">{{ vendor.rejection_reason }}</span>
              </div>
              <div v-if="vendor.notes" class="detail-item detail-item--full">
                <span class="detail-key">Internal Notes</span>
                <span class="detail-val" style="white-space:pre-wrap">{{ vendor.notes }}</span>
              </div>
            </div>
          </div>

          <!-- Renewal & Fiscal -->
          <div class="card" style="grid-column:1/-1">
            <div class="card-section-title">Partnership & Renewal</div>
            <div class="detail-grid">
              <div class="detail-item">
                <span class="detail-key">Partnership Level</span>
                <span class="detail-val">
                  <span v-if="vendor.partnership_level" class="badge" :class="partnershipBadge(vendor.partnership_level)">{{ vendor.partnership_level }}</span>
                  <span v-else>—</span>
                </span>
              </div>
              <div class="detail-item">
                <span class="detail-key">Fiscal Year Start</span>
                <span class="detail-val">{{ vendor.fiscal_year_start_month ? monthName(vendor.fiscal_year_start_month) : '—' }}</span>
              </div>
              <div class="detail-item">
                <span class="detail-key">Renewal Anniversary</span>
                <span class="detail-val">{{ fmtDate(vendor.renewal_anniversary_date ?? null) }}</span>
              </div>
              <div class="detail-item">
                <span class="detail-key">Next Renewal</span>
                <span class="detail-val">{{ nextRenewalDate }}</span>
              </div>
              <div class="detail-item">
                <span class="detail-key">Renewal Cost</span>
                <span class="detail-val">{{ vendor.renewal_cost != null ? (vendor.renewal_cost_currency ?? 'USD') + ' ' + vendor.renewal_cost.toLocaleString() : '—' }}</span>
              </div>
              <div class="detail-item">
                <span class="detail-key">Reminder (days before)</span>
                <span class="detail-val">{{ vendor.renewal_reminder_days ?? '—' }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <!-- TAB: Products & Solutions                                              -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'products'" class="tab-content">
        <div class="toolbar">
          <input v-model="productSearch" type="search" placeholder="Search products…" style="flex:1;max-width:260px;" />
          <select v-model="productSpaceFilter">
            <option value="">All tech spaces</option>
            <option v-for="s in TECH_SPACES" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
          <select v-model="productTypeFilter">
            <option value="">All types</option>
            <option value="product">Product</option>
            <option value="solution">Solution</option>
            <option value="service">Service</option>
            <option value="platform">Platform</option>
          </select>
          <button class="btn btn-primary btn-sm" @click="openAddProduct">+ Add Product</button>
        </div>
        <p v-if="productLoading" class="status">Loading…</p>
        <div v-else-if="filteredProducts.length === 0" class="card" style="text-align:center;padding:var(--space-8);">
          <p style="color:var(--color-text-muted)">No products yet. Add the first product or solution.</p>
          <button class="btn btn-primary btn-sm" style="margin-top:var(--space-3)" @click="openAddProduct">+ Add Product</button>
        </div>
        <div v-else class="card table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>SKU</th>
                <th>Type</th>
                <th>Technology Space</th>
                <th>Subcategory</th>
                <th>Status</th>
                <th style="text-align:right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in filteredProducts" :key="p.id">
                <td><strong>{{ p.name }}</strong></td>
                <td><span style="font-family:monospace;font-size:var(--font-size-xs);color:var(--color-text-muted)">{{ p.sku ?? '—' }}</span></td>
                <td><span class="badge badge-gray">{{ productTypeLabel(p.product_type) }}</span></td>
                <td>
                  <span class="space-chip" :style="{ background: spaceColor(p.technology_space) + '22', color: spaceColor(p.technology_space), borderColor: spaceColor(p.technology_space) + '55' }">
                    {{ spaceLabel(p.technology_space) }}
                  </span>
                </td>
                <td style="font-size:var(--font-size-sm)">{{ subcategoryLabel(p.technology_subcategory ?? '') }}</td>
                <td><span class="badge" :class="p.is_active ? 'badge-green' : 'badge-gray'">{{ p.is_active ? 'Active' : 'Inactive' }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <button class="btn btn-ghost btn-sm" @click="openEditProduct(p)">Edit</button>
                  <button class="btn btn-ghost btn-sm" style="color:var(--color-error)" @click="deleteProduct(p)">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <!-- TAB: Incentives & NFRs                                                 -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'incentives'" class="tab-content">
        <div class="toolbar">
          <input v-model="incentiveSearch" type="search" placeholder="Search incentives…" style="flex:1;max-width:260px;" />
          <select v-model="incentiveTypeFilter">
            <option value="">All types</option>
            <option value="rebate">Rebate</option>
            <option value="nfr">NFR (Not For Resale)</option>
            <option value="spiff">SPIFF</option>
            <option value="mdf">MDF</option>
            <option value="deal_registration">Deal Registration</option>
            <option value="co_op">Co-Op</option>
            <option value="other">Other</option>
          </select>
          <button class="btn btn-primary btn-sm" @click="openAddIncentive">+ Add Incentive</button>
        </div>
        <p v-if="incentiveLoading" class="status">Loading…</p>
        <div v-else-if="filteredIncentives.length === 0" class="card" style="text-align:center;padding:var(--space-8);">
          <p style="color:var(--color-text-muted)">No incentives or NFRs defined yet.</p>
          <button class="btn btn-primary btn-sm" style="margin-top:var(--space-3)" @click="openAddIncentive">+ Add Incentive</button>
        </div>
        <div v-else class="card table-wrap">
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Value</th>
                <th>Period</th>
                <th>Eligibility</th>
                <th>Status</th>
                <th style="text-align:right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="inc in filteredIncentives" :key="inc.id">
                <td>
                  <strong>{{ inc.name }}</strong>
                  <div v-if="inc.description" style="font-size:var(--font-size-xs);color:var(--color-text-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ inc.description }}</div>
                </td>
                <td><span class="badge" :class="incentiveBadge(inc.incentive_type)">{{ incentiveTypeLabel(inc.incentive_type) }}</span></td>
                <td>
                  <span v-if="inc.value !== null && inc.value_type === 'percentage'">{{ inc.value }}%</span>
                  <span v-else-if="inc.value !== null">{{ inc.currency ?? 'USD' }} {{ Number(inc.value).toLocaleString() }}</span>
                  <span v-else-if="inc.value_type === 'variable'" style="color:var(--color-text-muted)">Variable</span>
                  <span v-else style="color:var(--color-text-muted)">—</span>
                </td>
                <td style="font-size:var(--font-size-sm);white-space:nowrap">
                  <span v-if="inc.start_date || inc.end_date">
                    {{ inc.start_date ? fmtDate(inc.start_date) : '?' }} → {{ inc.end_date ? fmtDate(inc.end_date) : 'Ongoing' }}
                  </span>
                  <span v-else style="color:var(--color-text-muted)">No dates set</span>
                </td>
                <td style="font-size:var(--font-size-xs);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  {{ inc.eligibility_criteria ?? '—' }}
                </td>
                <td><span class="badge" :class="inc.is_active ? 'badge-green' : 'badge-gray'">{{ inc.is_active ? 'Active' : 'Inactive' }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <button class="btn btn-ghost btn-sm" @click="openEditIncentive(inc)">Edit</button>
                  <button class="btn btn-ghost btn-sm" style="color:var(--color-error)" @click="deleteIncentive(inc)">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <!-- TAB: Training & Certifications                                         -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'certifications'" class="tab-content">
        <div class="toolbar">
          <input v-model="certSearch" type="search" placeholder="Search certifications…" style="flex:1;max-width:260px;" />
          <select v-model="certSpaceFilter">
            <option value="">All tech spaces</option>
            <option v-for="s in TECH_SPACES" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
          <select v-model="certLevelFilter">
            <option value="">All levels</option>
            <option value="foundation">Foundation</option>
            <option value="associate">Associate</option>
            <option value="practitioner">Practitioner</option>
            <option value="professional">Professional</option>
            <option value="specialist">Specialist</option>
            <option value="expert">Expert</option>
          </select>
          <button class="btn btn-primary btn-sm" @click="openAddCert">+ Add Certification</button>
        </div>
        <p v-if="certLoading" class="status">Loading…</p>
        <div v-else-if="filteredCerts.length === 0" class="card" style="text-align:center;padding:var(--space-8);">
          <p style="color:var(--color-text-muted)">No certifications or training programs defined yet.</p>
          <button class="btn btn-primary btn-sm" style="margin-top:var(--space-3)" @click="openAddCert">+ Add Certification</button>
        </div>
        <div v-else class="card table-wrap">
          <table>
            <thead>
              <tr>
                <th>Certification Name</th>
                <th>Code</th>
                <th>Level</th>
                <th>Technology Space</th>
                <th>Validity</th>
                <th>Exam Cost</th>
                <th>Training Cost</th>
                <th>Status</th>
                <th style="text-align:right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in filteredCerts" :key="c.id">
                <td>
                  <strong>{{ c.name }}</strong>
                  <div v-if="c.technology_subcategory" style="font-size:var(--font-size-xs);color:var(--color-text-muted)">{{ subcategoryLabel(c.technology_subcategory) }}</div>
                  <span v-if="c.training_item_id" class="badge badge-blue" style="font-size:10px;padding:2px 6px;margin-top:2px;">Linked to compliance</span>
                </td>
                <td><span v-if="c.code" style="font-family:monospace;font-size:var(--font-size-xs);background:var(--color-border);padding:2px 6px;border-radius:4px;">{{ c.code }}</span><span v-else style="color:var(--color-text-muted)">—</span></td>
                <td><span class="badge" :class="certLevelBadge(c.level)">{{ certLevelLabel(c.level) }}</span></td>
                <td>
                  <span class="space-chip" :style="{ background: spaceColor(c.technology_space) + '22', color: spaceColor(c.technology_space), borderColor: spaceColor(c.technology_space) + '55' }">
                    {{ spaceLabel(c.technology_space) }}
                  </span>
                </td>
                <td style="font-size:var(--font-size-sm)">
                  <span v-if="c.validity_months">{{ c.validity_months }} months</span>
                  <span v-else style="color:var(--color-text-muted)">No expiry</span>
                </td>
                <td style="font-size:var(--font-size-sm)">
                  <span v-if="c.exam_cost_usd">USD {{ Number(c.exam_cost_usd).toLocaleString() }}</span>
                  <span v-else style="color:var(--color-text-muted)">—</span>
                </td>
                <td style="font-size:var(--font-size-sm)">
                  <span v-if="c.training_cost_usd">USD {{ Number(c.training_cost_usd).toLocaleString() }}</span>
                  <span v-else style="color:var(--color-text-muted)">—</span>
                </td>
                <td><span class="badge" :class="c.is_active ? 'badge-green' : 'badge-gray'">{{ c.is_active ? 'Active' : 'Inactive' }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <button class="btn btn-ghost btn-sm" @click="openEditCert(c)">Edit</button>
                  <button class="btn btn-ghost btn-sm" style="color:var(--color-error)" @click="deleteCert(c)">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <!-- TAB: Partnership                                                        -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'partnership'" class="tab-content">
        <!-- Current level banner -->
        <div v-if="currentTier" class="card" style="margin-bottom:var(--space-4);border-left:4px solid var(--color-brand-base);padding:var(--space-4) var(--space-5)">
          <div style="display:flex;align-items:center;gap:var(--space-3)">
            <span class="badge badge-blue" style="font-size:var(--font-size-sm);padding:var(--space-1) var(--space-3)">Current Level</span>
            <strong style="font-size:var(--font-size-lg)">{{ currentTier.tier_name }}</strong>
            <span v-if="currentTier.revenue_target" style="color:var(--color-text-muted);font-size:var(--font-size-sm)">Revenue target: {{ currentTier.revenue_currency }} {{ currentTier.revenue_target.toLocaleString() }}</span>
          </div>
        </div>

        <div class="toolbar">
          <span style="font-weight:var(--font-weight-semibold)">Partnership Tiers</span>
          <button class="btn btn-primary btn-sm" @click="openAddTier">+ Add Tier</button>
        </div>

        <p v-if="tierLoading" class="status">Loading…</p>

        <div v-else-if="tiers.length === 0" class="card" style="text-align:center;padding:var(--space-8)">
          <p style="color:var(--color-text-muted)">No partnership tiers defined yet. Add tiers to track partnership level requirements.</p>
          <button class="btn btn-primary btn-sm" style="margin-top:var(--space-3)" @click="openAddTier">+ Add Tier</button>
        </div>

        <div v-else class="tier-grid">
          <div
            v-for="tier in tiersOrdered"
            :key="tier.id"
            class="tier-card"
            :class="{ 'tier-card--current': tier.is_current }"
          >
            <div class="tier-card-header">
              <div style="display:flex;align-items:center;gap:var(--space-2)">
                <span class="badge badge-gray" style="font-size:11px">{{ tier.tier_order }}</span>
                <strong>{{ tier.tier_name }}</strong>
                <span v-if="tier.is_current" class="badge badge-green" style="font-size:11px">✓ Current</span>
              </div>
              <div style="display:flex;gap:var(--space-2)">
                <button v-if="!tier.is_current" class="btn btn-ghost btn-sm" @click="setCurrentTier(tier)">Set Current</button>
                <button class="btn btn-ghost btn-sm" @click="openEditTier(tier)">Edit</button>
                <button class="btn btn-ghost btn-sm" style="color:var(--color-error)" @click="deleteTier(tier)">Delete</button>
              </div>
            </div>
            <div class="tier-card-body">
              <div v-if="tier.revenue_target" class="tier-stat">
                <span class="detail-key">Revenue Target</span>
                <span>{{ tier.revenue_currency }} {{ tier.revenue_target.toLocaleString() }}</span>
              </div>
              <div v-if="tier.min_certifications" class="tier-stat">
                <span class="detail-key">Min. Certifications</span>
                <span>{{ tier.min_certifications }}</span>
              </div>
              <div v-if="tier.min_headcount" class="tier-stat">
                <span class="detail-key">Min. Headcount</span>
                <span>{{ tier.min_headcount }}</span>
              </div>
              <div v-if="tier.requirements" style="margin-top:var(--space-3)">
                <div class="detail-key" style="margin-bottom:4px">Requirements</div>
                <div style="font-size:var(--font-size-sm);white-space:pre-wrap;color:var(--color-text)">{{ tier.requirements }}</div>
              </div>
              <div v-if="tier.benefits" style="margin-top:var(--space-3)">
                <div class="detail-key" style="margin-bottom:4px">Benefits</div>
                <div style="font-size:var(--font-size-sm);white-space:pre-wrap;color:var(--color-text)">{{ tier.benefits }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <!-- TAB: Portal Users                                                       -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'portal_users'" class="tab-content">
        <div class="toolbar">
          <span style="font-weight:var(--font-weight-semibold)">Portal Users</span>
          <button class="btn btn-primary btn-sm" @click="openAddPortalUser">+ Add User</button>
        </div>

        <p v-if="portalLoading" class="status">Loading…</p>
        <div v-else-if="portalUsers.length === 0" class="card" style="text-align:center;padding:var(--space-8)">
          <p style="color:var(--color-text-muted)">No portal users associated with this vendor yet.</p>
          <button class="btn btn-primary btn-sm" style="margin-top:var(--space-3)" @click="openAddPortalUser">+ Add User</button>
        </div>
        <div v-else class="card table-wrap">
          <table>
            <thead><tr>
              <th>Display Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>Portal URL</th>
              <th>Access Level</th>
              <th>Status</th>
              <th style="text-align:right">Actions</th>
            </tr></thead>
            <tbody>
              <tr v-for="u in portalUsers" :key="u.id">
                <td><strong>{{ u.display_name }}</strong></td>
                <td style="font-size:var(--font-size-sm)">{{ u.email }}</td>
                <td><span class="badge" :class="roleBadge(u.role)">{{ u.role }}</span></td>
                <td style="font-size:var(--font-size-sm)">
                  <a v-if="u.portal_url" :href="u.portal_url" target="_blank" rel="noopener" style="color:var(--color-brand-base)">{{ u.portal_url }}</a>
                  <span v-else style="color:var(--color-text-muted)">—</span>
                </td>
                <td style="font-size:var(--font-size-sm)">{{ u.access_level ?? '—' }}</td>
                <td><span class="badge" :class="u.is_active ? 'badge-green' : 'badge-gray'">{{ u.is_active ? 'Active' : 'Inactive' }}</span></td>
                <td style="text-align:right;white-space:nowrap">
                  <button class="btn btn-ghost btn-sm" @click="openEditPortalUser(u)">Edit</button>
                  <button class="btn btn-ghost btn-sm" style="color:var(--color-error)" @click="deletePortalUser(u)">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <!-- TAB: Customers & Projects                                               -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'customers'" class="tab-content">
        <!-- Customers section -->
        <div class="toolbar" style="margin-bottom:var(--space-2)">
          <span style="font-weight:var(--font-weight-semibold)">Customers</span>
          <button class="btn btn-ghost btn-sm" v-if="selectedCustomerId" @click="selectedCustomerId = null">Clear Filter</button>
          <button class="btn btn-primary btn-sm" @click="openAddCustomer">+ Add Customer</button>
        </div>

        <p v-if="customerLoading" class="status">Loading customers…</p>
        <div v-else-if="customers.length === 0" class="card" style="text-align:center;padding:var(--space-6);margin-bottom:var(--space-4)">
          <p style="color:var(--color-text-muted)">No customers yet.</p>
        </div>
        <div v-else class="card table-wrap" style="margin-bottom:var(--space-5)">
          <table>
            <thead><tr>
              <th>Name</th>
              <th>Type</th>
              <th>Industry</th>
              <th>Country</th>
              <th>Contact</th>
              <th>Projects</th>
              <th>Status</th>
              <th style="text-align:right">Actions</th>
            </tr></thead>
            <tbody>
              <tr
                v-for="c in customers"
                :key="c.id"
                :class="{ 'row--selected': selectedCustomerId === c.id }"
                style="cursor:pointer"
                @click="selectedCustomerId = selectedCustomerId === c.id ? null : c.id"
              >
                <td><strong>{{ c.name }}</strong></td>
                <td><span class="badge badge-gray" style="text-transform:capitalize">{{ c.customer_type }}</span></td>
                <td style="font-size:var(--font-size-sm)">{{ c.industry ?? '—' }}</td>
                <td style="font-size:var(--font-size-sm)">{{ c.country }}</td>
                <td style="font-size:var(--font-size-sm)">
                  <div v-if="c.contact_name">{{ c.contact_name }}</div>
                  <a v-if="c.contact_email" :href="'mailto:' + c.contact_email" style="color:var(--color-brand-base);font-size:var(--font-size-xs)">{{ c.contact_email }}</a>
                </td>
                <td><span class="badge badge-blue">{{ c.projects_count }}</span></td>
                <td><span class="badge" :class="c.is_active ? 'badge-green' : 'badge-gray'">{{ c.is_active ? 'Active' : 'Inactive' }}</span></td>
                <td style="text-align:right;white-space:nowrap" @click.stop>
                  <button class="btn btn-ghost btn-sm" @click="openEditCustomer(c)">Edit</button>
                  <button class="btn btn-ghost btn-sm" style="color:var(--color-error)" @click="deleteCustomer(c)">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Projects section -->
        <div class="toolbar" style="margin-bottom:var(--space-2)">
          <span style="font-weight:var(--font-weight-semibold)">
            Projects
            <span v-if="selectedCustomerId" style="font-size:var(--font-size-sm);font-weight:normal;color:var(--color-text-muted)"> — {{ customers.find(c => c.id === selectedCustomerId)?.name }}</span>
          </span>
          <button class="btn btn-primary btn-sm" @click="openAddProject">+ Add Project</button>
        </div>

        <p v-if="projectLoading" class="status">Loading projects…</p>
        <div v-else-if="filteredProjects.length === 0" class="card" style="text-align:center;padding:var(--space-6)">
          <p style="color:var(--color-text-muted)">No projects found.</p>
        </div>
        <div v-else class="card table-wrap">
          <table>
            <thead><tr>
              <th>Project Name</th>
              <th>Code</th>
              <th>Customer</th>
              <th>Status</th>
              <th>Technology</th>
              <th>Dates</th>
              <th>Contract Value</th>
              <th style="text-align:right">Actions</th>
            </tr></thead>
            <tbody>
              <tr v-for="p in filteredProjects" :key="p.id">
                <td><strong>{{ p.name }}</strong></td>
                <td style="font-size:var(--font-size-xs);font-family:monospace;color:var(--color-text-muted)">{{ p.project_code ?? '—' }}</td>
                <td style="font-size:var(--font-size-sm)">{{ p.customer_name ?? '—' }}</td>
                <td><span class="badge" :class="projectStatusBadge(p.status)">{{ projectStatusLabel(p.status) }}</span></td>
                <td style="font-size:var(--font-size-sm)">
                  <div v-if="p.technology_space">
                    <span class="space-chip" :style="{ background: spaceColor(p.technology_space) + '22', color: spaceColor(p.technology_space), borderColor: spaceColor(p.technology_space) + '55' }">
                      {{ spaceLabel(p.technology_space) }}
                    </span>
                  </div>
                  <div v-if="p.technology_subcategory" style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin-top:2px">{{ subcategoryLabel(p.technology_subcategory) }}</div>
                </td>
                <td style="font-size:var(--font-size-sm)">
                  <div v-if="p.start_date || p.end_date">{{ fmtDate(p.start_date) }} – {{ fmtDate(p.end_date) }}</div>
                  <span v-else style="color:var(--color-text-muted)">—</span>
                </td>
                <td style="font-size:var(--font-size-sm)">
                  <span v-if="p.contract_value">{{ p.currency }} {{ p.contract_value.toLocaleString() }}</span>
                  <span v-else style="color:var(--color-text-muted)">—</span>
                </td>
                <td style="text-align:right;white-space:nowrap">
                  <button class="btn btn-ghost btn-sm" @click="openEditProject(p)">Edit</button>
                  <button class="btn btn-ghost btn-sm" style="color:var(--color-error)" @click="deleteProject(p)">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <!-- TAB: Technology Coverage                                               -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'coverage'" class="tab-content">
        <div v-if="techCoverage.length === 0" class="card" style="text-align:center;padding:var(--space-10);">
          <p style="color:var(--color-text-muted)">Add products and certifications to see the vendor's technology coverage map.</p>
        </div>
        <div v-else class="coverage-grid">
          <div
            v-for="entry in techCoverage"
            :key="entry.space.value"
            class="coverage-card"
            :style="{ borderTopColor: entry.space.color }"
          >
            <div class="coverage-card-header">
              <span class="coverage-space-name" :style="{ color: entry.space.color }">{{ entry.space.label }}</span>
              <div class="coverage-counters">
                <span v-if="entry.fromProducts.size > 0" title="Products / Solutions" style="font-size:var(--font-size-xs);color:var(--color-text-muted)">
                  📦 {{ entry.fromProducts.size }}
                </span>
                <span v-if="entry.fromCerts.size > 0" title="Certifications" style="font-size:var(--font-size-xs);color:var(--color-text-muted)">
                  🎓 {{ entry.fromCerts.size }}
                </span>
              </div>
            </div>
            <div class="coverage-subcats">
              <span
                v-for="sub in entry.allSubcats"
                :key="sub.value"
                class="coverage-subcat-chip"
                :class="{
                  'chip--product': entry.fromProducts.has(sub.value) && !entry.fromCerts.has(sub.value),
                  'chip--cert':    entry.fromCerts.has(sub.value) && !entry.fromProducts.has(sub.value),
                  'chip--both':    entry.fromProducts.has(sub.value) && entry.fromCerts.has(sub.value),
                }"
              >{{ sub.label }}</span>
            </div>
            <div class="coverage-legend">
              <span class="chip--product" style="padding:2px 6px;border-radius:4px;font-size:10px;">📦 Product</span>
              <span class="chip--cert"    style="padding:2px 6px;border-radius:4px;font-size:10px;">🎓 Cert</span>
              <span class="chip--both"    style="padding:2px 6px;border-radius:4px;font-size:10px;">Both</span>
            </div>
          </div>
        </div>
      </div>

    </template><!-- end v-if vendor -->

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- DRAWER: Product form                                                    -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div v-if="showProductForm" class="drawer-backdrop" @click.self="showProductForm = false">
      <div class="drawer-panel">
        <div class="drawer-header">
          <h3>{{ editingProduct ? 'Edit Product / Solution' : 'Add Product / Solution' }}</h3>
          <button class="btn btn-ghost btn-sm" @click="showProductForm = false">✕</button>
        </div>
        <div class="drawer-body">
          <div class="form-group">
            <label>Name <span style="color:var(--color-error)">*</span></label>
            <input v-model="pForm.name" type="text" placeholder="e.g. Cisco Catalyst 9300 Series" />
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>SKU / Part Number</label>
              <input v-model="pForm.sku" type="text" placeholder="e.g. C9300-48T-E" />
            </div>
            <div class="form-group">
              <label>Product Type <span style="color:var(--color-error)">*</span></label>
              <select v-model="pForm.product_type">
                <option value="product">Product</option>
                <option value="solution">Solution</option>
                <option value="service">Service</option>
                <option value="platform">Platform</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Technology Space <span style="color:var(--color-error)">*</span></label>
              <select v-model="pForm.technology_space" @change="pForm.technology_subcategory = ''">
                <option value="">— Select space —</option>
                <option v-for="s in TECH_SPACES" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>Subcategory <span style="color:var(--color-error)">*</span></label>
              <select v-model="pForm.technology_subcategory" :disabled="!pForm.technology_space">
                <option value="">— Select subcategory —</option>
                <option v-for="sub in subcategoriesFor(pForm.technology_space)" :key="sub.value" :value="sub.value">{{ sub.label }}</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Description</label>
            <textarea v-model="pForm.description" rows="4" placeholder="Brief description of this product or solution…" />
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:var(--space-3)">
            <input id="pActive" type="checkbox" v-model="pForm.is_active" style="width:auto" />
            <label for="pActive" style="margin:0;cursor:pointer">Active</label>
          </div>
          <p v-if="pFormError" class="error">{{ pFormError }}</p>
        </div>
        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="showProductForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveProduct">
            {{ saving ? 'Saving…' : (editingProduct ? 'Save Changes' : 'Add Product') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- DRAWER: Incentive form                                                  -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div v-if="showIncentiveForm" class="drawer-backdrop" @click.self="showIncentiveForm = false">
      <div class="drawer-panel">
        <div class="drawer-header">
          <h3>{{ editingIncentive ? 'Edit Incentive / NFR' : 'Add Incentive / NFR' }}</h3>
          <button class="btn btn-ghost btn-sm" @click="showIncentiveForm = false">✕</button>
        </div>
        <div class="drawer-body">
          <div class="form-group">
            <label>Program Name <span style="color:var(--color-error)">*</span></label>
            <input v-model="iForm.name" type="text" placeholder="e.g. Q2 Backend Rebate Program" />
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Incentive Type <span style="color:var(--color-error)">*</span></label>
              <select v-model="iForm.incentive_type">
                <option value="rebate">Rebate</option>
                <option value="nfr">NFR (Not For Resale)</option>
                <option value="spiff">SPIFF</option>
                <option value="mdf">MDF (Market Dev. Funds)</option>
                <option value="deal_registration">Deal Registration</option>
                <option value="co_op">Co-Op Funding</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="form-group">
              <label>Value Type <span style="color:var(--color-error)">*</span></label>
              <select v-model="iForm.value_type">
                <option value="percentage">Percentage (%)</option>
                <option value="fixed">Fixed Amount</option>
                <option value="per_unit">Per Unit</option>
                <option value="variable">Variable</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Value</label>
              <input v-model.number="iForm.value" type="number" step="0.01" min="0"
                :placeholder="iForm.value_type === 'percentage' ? 'e.g. 5 (for 5%)' : 'e.g. 50000'" />
            </div>
            <div class="form-group" v-if="iForm.value_type === 'fixed' || iForm.value_type === 'per_unit'">
              <label>Currency</label>
              <input v-model="iForm.currency" type="text" placeholder="KES / USD" maxlength="10" />
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Start Date</label>
              <input v-model="iForm.start_date" type="date" />
            </div>
            <div class="form-group">
              <label>End Date</label>
              <input v-model="iForm.end_date" type="date" />
            </div>
          </div>
          <div class="form-group">
            <label>Description</label>
            <textarea v-model="iForm.description" rows="3" placeholder="Describe this incentive program…" />
          </div>
          <div class="form-group">
            <label>Eligibility Criteria</label>
            <textarea v-model="iForm.eligibility_criteria" rows="3" placeholder="Who qualifies for this incentive? Minimum revenue thresholds, partner tiers, product categories, etc." />
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:var(--space-3)">
            <input id="iActive" type="checkbox" v-model="iForm.is_active" style="width:auto" />
            <label for="iActive" style="margin:0;cursor:pointer">Active</label>
          </div>
          <p v-if="iFormError" class="error">{{ iFormError }}</p>
        </div>
        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="showIncentiveForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveIncentive">
            {{ saving ? 'Saving…' : (editingIncentive ? 'Save Changes' : 'Add Incentive') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- DRAWER: Certification form                                              -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div v-if="showCertForm" class="drawer-backdrop" @click.self="showCertForm = false">
      <div class="drawer-panel">
        <div class="drawer-header">
          <h3>{{ editingCert ? 'Edit Certification' : 'Add Certification' }}</h3>
          <button class="btn btn-ghost btn-sm" @click="showCertForm = false">✕</button>
        </div>
        <div class="drawer-body">
          <div class="form-group">
            <label>Certification Name <span style="color:var(--color-error)">*</span></label>
            <input v-model="cForm.name" type="text" placeholder="e.g. Cisco Certified Network Associate (CCNA)" />
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Code / Acronym</label>
              <input v-model="cForm.code" type="text" placeholder="e.g. CCNA" maxlength="50" />
            </div>
            <div class="form-group">
              <label>Level <span style="color:var(--color-error)">*</span></label>
              <select v-model="cForm.level">
                <option value="foundation">Foundation</option>
                <option value="associate">Associate</option>
                <option value="practitioner">Practitioner</option>
                <option value="professional">Professional</option>
                <option value="specialist">Specialist</option>
                <option value="expert">Expert</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Technology Space <span style="color:var(--color-error)">*</span></label>
              <select v-model="cForm.technology_space" @change="cForm.technology_subcategory = ''">
                <option value="">— Select space —</option>
                <option v-for="s in TECH_SPACES" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>Subcategory</label>
              <select v-model="cForm.technology_subcategory" :disabled="!cForm.technology_space">
                <option value="">— Select subcategory —</option>
                <option v-for="sub in subcategoriesFor(cForm.technology_space)" :key="sub.value" :value="sub.value">{{ sub.label }}</option>
              </select>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Validity (months)</label>
              <input v-model.number="cForm.validity_months" type="number" min="1" max="120" placeholder="e.g. 36" />
            </div>
            <div class="form-group">
              <label>Exam Cost (USD)</label>
              <input v-model.number="cForm.exam_cost_usd" type="number" step="0.01" min="0" placeholder="e.g. 325" />
            </div>
          </div>
          <div class="form-group">
            <label>Training Cost (USD)</label>
            <input v-model.number="cForm.training_cost_usd" type="number" step="0.01" min="0" placeholder="e.g. 1500" />
          </div>
          <div class="form-group">
            <label>Link to Training Catalog Item</label>
            <select v-model.number="cForm.training_item_id">
              <option :value="null">— Not linked —</option>
              <option v-for="ti in trainingItems" :key="ti.id" :value="ti.id">{{ ti.title }}</option>
            </select>
            <p style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin-top:var(--space-1);">
              Linking enables this certification to appear as a compliance requirement option.
            </p>
          </div>
          <div class="form-group">
            <label>Description</label>
            <textarea v-model="cForm.description" rows="3" placeholder="What does this certification validate?" />
          </div>
          <div class="form-group">
            <label>Prerequisites</label>
            <textarea v-model="cForm.prerequisites" rows="2" placeholder="Any required prior certifications or experience…" />
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:var(--space-3)">
            <input id="cActive" type="checkbox" v-model="cForm.is_active" style="width:auto" />
            <label for="cActive" style="margin:0;cursor:pointer">Active</label>
          </div>
          <p v-if="cFormError" class="error">{{ cFormError }}</p>
        </div>
        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="showCertForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveCert">
            {{ saving ? 'Saving…' : (editingCert ? 'Save Changes' : 'Add Certification') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- DRAWER: Partnership Tier form                                            -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div v-if="showTierForm" class="drawer-backdrop" @click.self="showTierForm = false">
      <div class="drawer-panel">
        <div class="drawer-header">
          <h3>{{ editingTier ? 'Edit Tier' : 'Add Tier' }}</h3>
          <button class="btn btn-ghost btn-sm" @click="showTierForm = false">✕</button>
        </div>
        <div class="drawer-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Tier Name <span style="color:var(--color-error)">*</span></label>
              <input v-model="tForm.tier_name" type="text" placeholder="e.g. Gold Partner" />
            </div>
            <div class="form-group">
              <label>Tier Order</label>
              <input v-model.number="tForm.tier_order" type="number" min="0" placeholder="e.g. 3" />
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Revenue Target</label>
              <input v-model.number="tForm.revenue_target" type="number" step="0.01" min="0" />
            </div>
            <div class="form-group">
              <label>Currency</label>
              <input v-model="tForm.revenue_currency" type="text" maxlength="10" placeholder="USD" />
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Min. Certifications</label>
              <input v-model.number="tForm.min_certifications" type="number" min="0" />
            </div>
            <div class="form-group">
              <label>Min. Headcount</label>
              <input v-model.number="tForm.min_headcount" type="number" min="0" />
            </div>
          </div>
          <div class="form-group">
            <label>Requirements</label>
            <textarea v-model="tForm.requirements" rows="4" placeholder="Requirements to achieve this tier level…" />
          </div>
          <div class="form-group">
            <label>Benefits</label>
            <textarea v-model="tForm.benefits" rows="4" placeholder="Benefits at this tier level…" />
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:var(--space-3)">
            <input id="tCurrent" type="checkbox" v-model="tForm.is_current" style="width:auto" />
            <label for="tCurrent" style="margin:0;cursor:pointer">Mark as Current Level</label>
          </div>
          <p v-if="tFormError" class="error">{{ tFormError }}</p>
        </div>
        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="showTierForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveTier">
            {{ saving ? 'Saving…' : (editingTier ? 'Save Changes' : 'Add Tier') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- DRAWER: Portal User form                                                 -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div v-if="showPortalForm" class="drawer-backdrop" @click.self="showPortalForm = false">
      <div class="drawer-panel">
        <div class="drawer-header">
          <h3>{{ editingPortalUser ? 'Edit Portal User' : 'Add Portal User' }}</h3>
          <button class="btn btn-ghost btn-sm" @click="showPortalForm = false">✕</button>
        </div>
        <div class="drawer-body">
          <div class="form-group">
            <label>Display Name <span style="color:var(--color-error)">*</span></label>
            <input v-model="puForm.display_name" type="text" placeholder="e.g. Jane Mwangi" />
          </div>
          <div class="form-group">
            <label>Email <span style="color:var(--color-error)">*</span></label>
            <input v-model="puForm.email" type="email" placeholder="jane@vendor.com" />
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Role</label>
              <select v-model="puForm.role">
                <option value="admin">Admin</option>
                <option value="user">User</option>
                <option value="viewer">Viewer</option>
              </select>
            </div>
            <div class="form-group">
              <label>Access Level</label>
              <input v-model="puForm.access_level" type="text" placeholder="e.g. Full / Read-only" />
            </div>
          </div>
          <div class="form-group">
            <label>Portal URL</label>
            <input v-model="puForm.portal_url" type="url" placeholder="https://partnerportal.vendor.com" />
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:var(--space-3)">
            <input id="puActive" type="checkbox" v-model="puForm.is_active" style="width:auto" />
            <label for="puActive" style="margin:0;cursor:pointer">Active</label>
          </div>
          <p v-if="puFormError" class="error">{{ puFormError }}</p>
        </div>
        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="showPortalForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="savePortalUser">
            {{ saving ? 'Saving…' : (editingPortalUser ? 'Save Changes' : 'Add User') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- DRAWER: Customer form                                                    -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div v-if="showCustomerForm" class="drawer-backdrop" @click.self="showCustomerForm = false">
      <div class="drawer-panel">
        <div class="drawer-header">
          <h3>{{ editingCustomer ? 'Edit Customer' : 'Add Customer' }}</h3>
          <button class="btn btn-ghost btn-sm" @click="showCustomerForm = false">✕</button>
        </div>
        <div class="drawer-body">
          <div class="form-group">
            <label>Customer Name <span style="color:var(--color-error)">*</span></label>
            <input v-model="custForm.name" type="text" placeholder="e.g. Safaricom PLC" />
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Customer Type</label>
              <select v-model="custForm.customer_type">
                <option value="enterprise">Enterprise</option>
                <option value="sme">SME</option>
                <option value="government">Government</option>
                <option value="ngo">NGO</option>
                <option value="education">Education</option>
                <option value="telco">Telco</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="form-group">
              <label>Industry</label>
              <input v-model="custForm.industry" type="text" placeholder="e.g. Telecommunications" />
            </div>
          </div>
          <div class="form-group">
            <label>Country</label>
            <input v-model="custForm.country" type="text" placeholder="Kenya" />
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Contact Name</label>
              <input v-model="custForm.contact_name" type="text" />
            </div>
            <div class="form-group">
              <label>Contact Phone</label>
              <input v-model="custForm.contact_phone" type="text" />
            </div>
          </div>
          <div class="form-group">
            <label>Contact Email</label>
            <input v-model="custForm.contact_email" type="email" />
          </div>
          <div class="form-group">
            <label>Notes</label>
            <textarea v-model="custForm.notes" rows="3" />
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:var(--space-3)">
            <input id="custActive" type="checkbox" v-model="custForm.is_active" style="width:auto" />
            <label for="custActive" style="margin:0;cursor:pointer">Active</label>
          </div>
          <p v-if="custFormError" class="error">{{ custFormError }}</p>
        </div>
        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="showCustomerForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveCustomer">
            {{ saving ? 'Saving…' : (editingCustomer ? 'Save Changes' : 'Add Customer') }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- DRAWER: Project form                                                     -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div v-if="showProjectForm" class="drawer-backdrop" @click.self="showProjectForm = false">
      <div class="drawer-panel">
        <div class="drawer-header">
          <h3>{{ editingProject ? 'Edit Project' : 'Add Project' }}</h3>
          <button class="btn btn-ghost btn-sm" @click="showProjectForm = false">✕</button>
        </div>
        <div class="drawer-body">
          <div class="form-group">
            <label>Project Name <span style="color:var(--color-error)">*</span></label>
            <input v-model="projForm.name" type="text" placeholder="e.g. Network Refresh Phase 2" />
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Project Code</label>
              <input v-model="projForm.project_code" type="text" placeholder="e.g. PRJ-2024-042" />
            </div>
            <div class="form-group">
              <label>Status</label>
              <select v-model="projForm.status">
                <option value="pipeline">Pipeline</option>
                <option value="active">Active</option>
                <option value="on_hold">On Hold</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Customer</label>
            <select v-model.number="projForm.vendor_customer_id">
              <option :value="null">— No specific customer —</option>
              <option v-for="c in customers" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Technology Space</label>
              <select v-model="projForm.technology_space" @change="projForm.technology_subcategory = ''">
                <option value="">— None —</option>
                <option v-for="s in TECH_SPACES" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>Subcategory</label>
              <select v-model="projForm.technology_subcategory" :disabled="!projForm.technology_space">
                <option value="">— None —</option>
                <option v-for="sub in subcategoriesFor(projForm.technology_space)" :key="sub.value" :value="sub.value">{{ sub.label }}</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Description</label>
            <textarea v-model="projForm.description" rows="3" />
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Start Date</label>
              <input v-model="projForm.start_date" type="date" />
            </div>
            <div class="form-group">
              <label>End Date</label>
              <input v-model="projForm.end_date" type="date" />
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Contract Value</label>
              <input v-model.number="projForm.contract_value" type="number" step="0.01" min="0" />
            </div>
            <div class="form-group">
              <label>Currency</label>
              <input v-model="projForm.currency" type="text" maxlength="10" placeholder="KES" />
            </div>
          </div>
          <div class="form-group">
            <label>Notes</label>
            <textarea v-model="projForm.notes" rows="3" />
          </div>
          <p v-if="projFormError" class="error">{{ projFormError }}</p>
        </div>
        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="showProjectForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="saveProject">
            {{ saving ? 'Saving…' : (editingProject ? 'Save Changes' : 'Add Project') }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/lib/api';
import { currentOrgSlug } from '@/stores/auth';
import { TECH_SPACES, spaceLabel, subcategoryLabel, subcategoriesFor, spaceColor } from '@/lib/tech-spaces';
import type {
  Vendor, VendorOnboardingStatus, VendorType, ProductType,
  VendorProduct, IncentiveType, ValueType, VendorIncentive,
  CertLevel, VendorCertification, TrainingItem,
  VendorPartnershipTier, VendorPortalUser, PortalUserRole,
  VendorCustomer, CustomerType, VendorProject, ProjectStatus,
} from '@/lib/types';

// ── Setup ────────────────────────────────────────────────────────────────────

const route  = useRoute();
const router = useRouter();

function org(): string | null { return currentOrgSlug.value; }

const vendorId = Number(route.params.id);

// ── Tabs ─────────────────────────────────────────────────────────────────────

const TABS = [
  { id: 'overview',       label: 'Overview' },
  { id: 'products',       label: 'Products & Solutions' },
  { id: 'incentives',     label: 'Incentives & NFRs' },
  { id: 'certifications', label: 'Training & Certifications' },
  { id: 'partnership',    label: 'Partnership' },
  { id: 'portal_users',  label: 'Portal Users' },
  { id: 'customers',     label: 'Customers & Projects' },
  { id: 'coverage',       label: 'Technology Coverage' },
] as const;

type TabId = typeof TABS[number]['id'];

const activeTab   = ref<TabId>('overview');
const loadedTabs  = new Set<TabId>(['overview']);

function switchTab(id: TabId) {
  activeTab.value = id;
  if (!loadedTabs.has(id)) {
    loadedTabs.add(id);
    if (id === 'products')       loadProducts();
    if (id === 'incentives')     loadIncentives();
    if (id === 'certifications') { loadCerts(); loadTrainingItems(); }
    if (id === 'partnership')    loadTiers();
    if (id === 'portal_users')   loadPortalUsers();
    if (id === 'customers')      { loadCustomers(); loadProjects(); }
  }
}

// ── Vendor ───────────────────────────────────────────────────────────────────

const vendor        = ref<Vendor | null>(null);
const vendorLoading = ref(true);

async function loadVendor() {
  vendorLoading.value = true;
  try {
    const res = await api<{ data: Vendor }>('GET', `/api/v1/vendors/${vendorId}`, { org: org() });
    vendor.value = res.data;
  } catch {
    vendor.value = null;
  } finally {
    vendorLoading.value = false;
  }
}

// Vendor edit form is handled by VendorsView — navigate back and trigger edit
function openEditVendor() {
  router.push({ name: 'vendors' });
}

// ── Products ─────────────────────────────────────────────────────────────────

const products      = ref<VendorProduct[]>([]);
const productLoading = ref(false);
const productSearch  = ref('');
const productSpaceFilter = ref('');
const productTypeFilter  = ref('');

async function loadProducts() {
  productLoading.value = true;
  try {
    const res = await api<{ data: VendorProduct[] }>('GET', `/api/v1/vendors/${vendorId}/products`, { org: org() });
    products.value = res.data;
  } finally {
    productLoading.value = false;
  }
}

const filteredProducts = computed(() => {
  return products.value.filter(p => {
    if (productSearch.value) {
      const t = productSearch.value.toLowerCase();
      if (!p.name.toLowerCase().includes(t) && !(p.sku ?? '').toLowerCase().includes(t)) return false;
    }
    if (productSpaceFilter.value && p.technology_space !== productSpaceFilter.value) return false;
    if (productTypeFilter.value && p.product_type !== productTypeFilter.value) return false;
    return true;
  });
});

// Product form
const showProductForm = ref(false);
const editingProduct  = ref<VendorProduct | null>(null);
const pFormError      = ref<string | null>(null);

const pForm = reactive({
  name: '', sku: '', product_type: 'product' as ProductType,
  technology_space: '', technology_subcategory: '',
  description: '', is_active: true,
});

function resetPForm() {
  pForm.name = ''; pForm.sku = ''; pForm.product_type = 'product';
  pForm.technology_space = ''; pForm.technology_subcategory = '';
  pForm.description = ''; pForm.is_active = true;
  pFormError.value = null;
}

function openAddProduct() {
  editingProduct.value = null;
  resetPForm();
  showProductForm.value = true;
}

function openEditProduct(p: VendorProduct) {
  editingProduct.value = p;
  pForm.name = p.name; pForm.sku = p.sku ?? '';
  pForm.product_type = p.product_type;
  pForm.technology_space = p.technology_space;
  pForm.technology_subcategory = p.technology_subcategory ?? '';
  pForm.description = p.description ?? '';
  pForm.is_active = p.is_active;
  pFormError.value = null;
  showProductForm.value = true;
}

async function saveProduct() {
  if (!pForm.name.trim()) { pFormError.value = 'Name is required.'; return; }
  if (!pForm.technology_space) { pFormError.value = 'Technology space is required.'; return; }
  saving.value = true; pFormError.value = null;
  const body = {
    name: pForm.name.trim(), sku: pForm.sku.trim() || null,
    product_type: pForm.product_type,
    technology_space: pForm.technology_space,
    technology_subcategory: pForm.technology_subcategory || null,
    description: pForm.description.trim() || null,
    is_active: pForm.is_active,
  };
  try {
    if (editingProduct.value) {
      const res = await api<{ data: VendorProduct }>('PUT', `/api/v1/vendors/${vendorId}/products/${editingProduct.value.id}`, { org: org(), body });
      const idx = products.value.findIndex(x => x.id === editingProduct.value!.id);
      if (idx !== -1) products.value[idx] = res.data;
    } else {
      const res = await api<{ data: VendorProduct }>('POST', `/api/v1/vendors/${vendorId}/products`, { org: org(), body });
      products.value.push(res.data);
    }
    showProductForm.value = false;
  } catch (e: any) {
    pFormError.value = e?.message ?? 'Failed to save.';
  } finally {
    saving.value = false;
  }
}

async function deleteProduct(p: VendorProduct) {
  if (!confirm(`Delete "${p.name}"?`)) return;
  await api('DELETE', `/api/v1/vendors/${vendorId}/products/${p.id}`, { org: org() });
  products.value = products.value.filter(x => x.id !== p.id);
}

// ── Incentives ───────────────────────────────────────────────────────────────

const incentives       = ref<VendorIncentive[]>([]);
const incentiveLoading = ref(false);
const incentiveSearch  = ref('');
const incentiveTypeFilter = ref('');

async function loadIncentives() {
  incentiveLoading.value = true;
  try {
    const res = await api<{ data: VendorIncentive[] }>('GET', `/api/v1/vendors/${vendorId}/incentives`, { org: org() });
    incentives.value = res.data;
  } finally {
    incentiveLoading.value = false;
  }
}

const filteredIncentives = computed(() => {
  return incentives.value.filter(i => {
    if (incentiveSearch.value && !i.name.toLowerCase().includes(incentiveSearch.value.toLowerCase())) return false;
    if (incentiveTypeFilter.value && i.incentive_type !== incentiveTypeFilter.value) return false;
    return true;
  });
});

// Incentive form
const showIncentiveForm = ref(false);
const editingIncentive  = ref<VendorIncentive | null>(null);
const iFormError        = ref<string | null>(null);

const iForm = reactive({
  name: '', incentive_type: 'rebate' as IncentiveType,
  description: '', value: null as number | null,
  value_type: 'percentage' as ValueType, currency: '',
  eligibility_criteria: '', start_date: '', end_date: '',
  is_active: true,
});

function resetIForm() {
  iForm.name = ''; iForm.incentive_type = 'rebate';
  iForm.description = ''; iForm.value = null;
  iForm.value_type = 'percentage'; iForm.currency = '';
  iForm.eligibility_criteria = ''; iForm.start_date = ''; iForm.end_date = '';
  iForm.is_active = true; iFormError.value = null;
}

function openAddIncentive() {
  editingIncentive.value = null; resetIForm();
  showIncentiveForm.value = true;
}

function openEditIncentive(inc: VendorIncentive) {
  editingIncentive.value = inc;
  iForm.name = inc.name; iForm.incentive_type = inc.incentive_type;
  iForm.description = inc.description ?? '';
  iForm.value = inc.value; iForm.value_type = inc.value_type;
  iForm.currency = inc.currency ?? '';
  iForm.eligibility_criteria = inc.eligibility_criteria ?? '';
  iForm.start_date = inc.start_date ?? ''; iForm.end_date = inc.end_date ?? '';
  iForm.is_active = inc.is_active; iFormError.value = null;
  showIncentiveForm.value = true;
}

async function saveIncentive() {
  if (!iForm.name.trim()) { iFormError.value = 'Name is required.'; return; }
  saving.value = true; iFormError.value = null;
  const body = {
    name: iForm.name.trim(), incentive_type: iForm.incentive_type,
    description: iForm.description.trim() || null,
    value: iForm.value, value_type: iForm.value_type,
    currency: iForm.currency.trim() || null,
    eligibility_criteria: iForm.eligibility_criteria.trim() || null,
    start_date: iForm.start_date || null, end_date: iForm.end_date || null,
    is_active: iForm.is_active,
  };
  try {
    if (editingIncentive.value) {
      const res = await api<{ data: VendorIncentive }>('PUT', `/api/v1/vendors/${vendorId}/incentives/${editingIncentive.value.id}`, { org: org(), body });
      const idx = incentives.value.findIndex(x => x.id === editingIncentive.value!.id);
      if (idx !== -1) incentives.value[idx] = res.data;
    } else {
      const res = await api<{ data: VendorIncentive }>('POST', `/api/v1/vendors/${vendorId}/incentives`, { org: org(), body });
      incentives.value.push(res.data);
    }
    showIncentiveForm.value = false;
  } catch (e: any) {
    iFormError.value = e?.message ?? 'Failed to save.';
  } finally {
    saving.value = false;
  }
}

async function deleteIncentive(inc: VendorIncentive) {
  if (!confirm(`Delete "${inc.name}"?`)) return;
  await api('DELETE', `/api/v1/vendors/${vendorId}/incentives/${inc.id}`, { org: org() });
  incentives.value = incentives.value.filter(x => x.id !== inc.id);
}

// ── Certifications ────────────────────────────────────────────────────────────

const certs       = ref<VendorCertification[]>([]);
const certLoading  = ref(false);
const certSearch   = ref('');
const certSpaceFilter = ref('');
const certLevelFilter = ref('');

// Training catalog — loaded once when certifications tab opens, used for linking
const trainingItems     = ref<TrainingItem[]>([]);
const trainingItemsLoaded = ref(false);

async function loadTrainingItems() {
  if (trainingItemsLoaded.value) return;
  try {
    const res = await api<{ data: TrainingItem[] }>('GET', '/api/v1/training/catalog', { org: org(), params: { per_page: 200, is_active: '1' } });
    trainingItems.value = res.data ?? [];
    trainingItemsLoaded.value = true;
  } catch {
    // non-critical — dropdown just stays empty
  }
}

async function loadCerts() {
  certLoading.value = true;
  try {
    const res = await api<{ data: VendorCertification[] }>('GET', `/api/v1/vendors/${vendorId}/certifications`, { org: org() });
    certs.value = res.data;
  } finally {
    certLoading.value = false;
  }
}

const filteredCerts = computed(() => {
  return certs.value.filter(c => {
    if (certSearch.value) {
      const t = certSearch.value.toLowerCase();
      if (!c.name.toLowerCase().includes(t) && !(c.code ?? '').toLowerCase().includes(t)) return false;
    }
    if (certSpaceFilter.value && c.technology_space !== certSpaceFilter.value) return false;
    if (certLevelFilter.value && c.level !== certLevelFilter.value) return false;
    return true;
  });
});

// Cert form
const showCertForm = ref(false);
const editingCert  = ref<VendorCertification | null>(null);
const cFormError   = ref<string | null>(null);

const cForm = reactive({
  name: '', code: '', level: 'associate' as CertLevel,
  technology_space: '', technology_subcategory: '',
  description: '', validity_months: null as number | null,
  exam_cost_usd: null as number | null, training_cost_usd: null as number | null, prerequisites: '',
  training_item_id: null as number | null,
  is_active: true,
});

function resetCForm() {
  cForm.name = ''; cForm.code = ''; cForm.level = 'associate';
  cForm.technology_space = ''; cForm.technology_subcategory = '';
  cForm.description = ''; cForm.validity_months = null;
  cForm.exam_cost_usd = null; cForm.training_cost_usd = null; cForm.prerequisites = '';
  cForm.training_item_id = null;
  cForm.is_active = true; cFormError.value = null;
}

function openAddCert() {
  editingCert.value = null; resetCForm();
  showCertForm.value = true;
}

function openEditCert(c: VendorCertification) {
  editingCert.value = c;
  cForm.name = c.name; cForm.code = c.code ?? '';
  cForm.level = c.level; cForm.technology_space = c.technology_space;
  cForm.technology_subcategory = c.technology_subcategory ?? '';
  cForm.description = c.description ?? '';
  cForm.validity_months = c.validity_months; cForm.exam_cost_usd = c.exam_cost_usd; cForm.training_cost_usd = c.training_cost_usd ?? null;
  cForm.training_item_id = c.training_item_id ?? null;
  cForm.prerequisites = c.prerequisites ?? ''; cForm.is_active = c.is_active;
  cFormError.value = null; showCertForm.value = true;
}

async function saveCert() {
  if (!cForm.name.trim()) { cFormError.value = 'Name is required.'; return; }
  if (!cForm.technology_space) { cFormError.value = 'Technology space is required.'; return; }
  saving.value = true; cFormError.value = null;
  const body = {
    name: cForm.name.trim(), code: cForm.code.trim() || null,
    level: cForm.level, technology_space: cForm.technology_space,
    technology_subcategory: cForm.technology_subcategory || null,
    description: cForm.description.trim() || null,
    validity_months: cForm.validity_months, exam_cost_usd: cForm.exam_cost_usd, training_cost_usd: cForm.training_cost_usd,
    training_item_id: cForm.training_item_id,
    prerequisites: cForm.prerequisites.trim() || null,
    is_active: cForm.is_active,
  };
  try {
    if (editingCert.value) {
      const res = await api<{ data: VendorCertification }>('PUT', `/api/v1/vendors/${vendorId}/certifications/${editingCert.value.id}`, { org: org(), body });
      const idx = certs.value.findIndex(x => x.id === editingCert.value!.id);
      if (idx !== -1) certs.value[idx] = res.data;
    } else {
      const res = await api<{ data: VendorCertification }>('POST', `/api/v1/vendors/${vendorId}/certifications`, { org: org(), body });
      certs.value.push(res.data);
    }
    showCertForm.value = false;
  } catch (e: any) {
    cFormError.value = e?.message ?? 'Failed to save.';
  } finally {
    saving.value = false;
  }
}

async function deleteCert(c: VendorCertification) {
  if (!confirm(`Delete "${c.name}"?`)) return;
  await api('DELETE', `/api/v1/vendors/${vendorId}/certifications/${c.id}`, { org: org() });
  certs.value = certs.value.filter(x => x.id !== c.id);
}

// ── Technology Coverage (computed) ────────────────────────────────────────────

interface CoverageEntry {
  space: typeof TECH_SPACES[number];
  fromProducts: Set<string>;
  fromCerts:    Set<string>;
  allSubcats:   { value: string; label: string }[];
}

const techCoverage = computed((): CoverageEntry[] => {
  const map = new Map<string, CoverageEntry>();

  products.value.forEach(p => {
    if (!map.has(p.technology_space)) {
      const space = TECH_SPACES.find(s => s.value === p.technology_space);
      if (!space) return;
      map.set(p.technology_space, { space, fromProducts: new Set(), fromCerts: new Set(), allSubcats: [] });
    }
    if (p.technology_subcategory) map.get(p.technology_space)!.fromProducts.add(p.technology_subcategory);
  });

  certs.value.forEach(c => {
    if (!map.has(c.technology_space)) {
      const space = TECH_SPACES.find(s => s.value === c.technology_space);
      if (!space) return;
      map.set(c.technology_space, { space, fromProducts: new Set(), fromCerts: new Set(), allSubcats: [] });
    }
    if (c.technology_subcategory) map.get(c.technology_space)!.fromCerts.add(c.technology_subcategory);
  });

  // Build allSubcats = union of fromProducts + fromCerts (with labels from TECH_SPACES)
  map.forEach(entry => {
    const allVals = new Set([...entry.fromProducts, ...entry.fromCerts]);
    const subcatMap = new Map(entry.space.subcategories.map(s => [s.value, s.label]));
    entry.allSubcats = [...allVals].map(v => ({ value: v, label: subcatMap.get(v) ?? v }));
  });

  return [...map.values()].sort((a, b) => a.space.label.localeCompare(b.space.label));
});

// ── Shared saving flag ────────────────────────────────────────────────────────

const saving = ref(false);

// ── Helpers ──────────────────────────────────────────────────────────────────

function typeLabel(t: VendorType | string): string {
  const m: Record<string, string> = { supplier: 'Supplier', service_provider: 'Service Provider', contractor: 'Contractor', consultant: 'Consultant', distributor: 'Distributor', partner: 'Partner' };
  return m[t] ?? t;
}
function statusLabel(s: VendorOnboardingStatus | string): string {
  const m: Record<string, string> = { draft: 'Draft', pending_review: 'Pending Review', approved: 'Approved', rejected: 'Rejected', suspended: 'Suspended' };
  return m[s] ?? s;
}
function statusBadge(s: VendorOnboardingStatus | string): string {
  const m: Record<string, string> = { draft: 'badge-gray', pending_review: 'badge-amber', approved: 'badge-green', rejected: 'badge-red', suspended: 'badge-red' };
  return m[s] ?? 'badge-gray';
}
function productTypeLabel(t: ProductType | string): string {
  const m: Record<string, string> = { product: 'Product', solution: 'Solution', service: 'Service', platform: 'Platform' };
  return m[t] ?? t;
}
function incentiveTypeLabel(t: IncentiveType | string): string {
  const m: Record<string, string> = { rebate: 'Rebate', nfr: 'NFR', spiff: 'SPIFF', mdf: 'MDF', deal_registration: 'Deal Reg.', co_op: 'Co-Op', other: 'Other' };
  return m[t] ?? t;
}
function incentiveBadge(t: IncentiveType | string): string {
  const m: Record<string, string> = { rebate: 'badge-green', nfr: 'badge-blue', spiff: 'badge-amber', mdf: 'badge-blue', deal_registration: 'badge-gray', co_op: 'badge-gray', other: 'badge-gray' };
  return m[t] ?? 'badge-gray';
}
function certLevelLabel(l: CertLevel | string): string {
  const m: Record<string, string> = { foundation: 'Foundation', associate: 'Associate', practitioner: 'Practitioner', professional: 'Professional', specialist: 'Specialist', expert: 'Expert' };
  return m[l] ?? l;
}
function certLevelBadge(l: CertLevel | string): string {
  const m: Record<string, string> = { foundation: 'badge-gray', associate: 'badge-blue', practitioner: 'badge-blue', professional: 'badge-amber', specialist: 'badge-amber', expert: 'badge-green' };
  return m[l] ?? 'badge-gray';
}
function roleBadge(r: PortalUserRole | string): string {
  const m: Record<string, string> = { admin: 'badge-red', user: 'badge-blue', viewer: 'badge-gray' };
  return m[r] ?? 'badge-gray';
}
function projectStatusLabel(s: ProjectStatus | string): string {
  const m: Record<string, string> = { active: 'Active', completed: 'Completed', on_hold: 'On Hold', cancelled: 'Cancelled', pipeline: 'Pipeline' };
  return m[s] ?? s;
}
function projectStatusBadge(s: ProjectStatus | string): string {
  const m: Record<string, string> = { active: 'badge-green', completed: 'badge-blue', on_hold: 'badge-amber', cancelled: 'badge-red', pipeline: 'badge-gray' };
  return m[s] ?? 'badge-gray';
}
function partnershipBadge(level: string): string {
  const m: Record<string, string> = { Registered: 'badge-gray', Silver: 'badge-blue', Gold: 'badge-amber', Platinum: 'badge-green', Elite: 'badge-green', Titanium: 'badge-blue', Diamond: 'badge-blue' };
  return m[level] ?? 'badge-gray';
}
function monthName(m: number): string {
  const names = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  return names[m - 1] ?? '—';
}
const nextRenewalDate = computed((): string => {
  if (!vendor.value?.renewal_anniversary_date) return '—';
  const ann = new Date(vendor.value.renewal_anniversary_date);
  const today = new Date();
  const next = new Date(today.getFullYear(), ann.getMonth(), ann.getDate());
  if (next < today) next.setFullYear(next.getFullYear() + 1);
  return next.toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' });
});
function fmtDate(d: string | null): string {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' });
}

// ── Partnership Tiers ─────────────────────────────────────────────────────────

const tiers       = ref<VendorPartnershipTier[]>([]);
const tierLoading = ref(false);
const showTierForm = ref(false);
const editingTier  = ref<VendorPartnershipTier | null>(null);
const tFormError   = ref<string | null>(null);
const tForm = reactive({
  tier_name: '', tier_order: 0,
  revenue_target: null as number | null, revenue_currency: 'USD',
  min_certifications: null as number | null, min_headcount: null as number | null,
  requirements: '', benefits: '', is_current: false,
});

const tiersOrdered = computed(() => [...tiers.value].sort((a, b) => a.tier_order - b.tier_order));
const currentTier  = computed(() => tiers.value.find(t => t.is_current) ?? null);

async function loadTiers() {
  tierLoading.value = true;
  try {
    const res = await api<{ data: VendorPartnershipTier[] }>('GET', `/api/v1/vendors/${vendorId}/tiers`, { org: org() });
    tiers.value = res.data;
  } finally { tierLoading.value = false; }
}

function openAddTier() {
  editingTier.value = null;
  tForm.tier_name = ''; tForm.tier_order = 0;
  tForm.revenue_target = null; tForm.revenue_currency = 'USD';
  tForm.min_certifications = null; tForm.min_headcount = null;
  tForm.requirements = ''; tForm.benefits = ''; tForm.is_current = false;
  tFormError.value = null;
  showTierForm.value = true;
}

function openEditTier(t: VendorPartnershipTier) {
  editingTier.value = t;
  tForm.tier_name = t.tier_name; tForm.tier_order = t.tier_order;
  tForm.revenue_target = t.revenue_target; tForm.revenue_currency = t.revenue_currency;
  tForm.min_certifications = t.min_certifications; tForm.min_headcount = t.min_headcount;
  tForm.requirements = t.requirements ?? ''; tForm.benefits = t.benefits ?? '';
  tForm.is_current = t.is_current;
  tFormError.value = null;
  showTierForm.value = true;
}

async function saveTier() {
  if (!tForm.tier_name.trim()) { tFormError.value = 'Tier name is required.'; return; }
  saving.value = true; tFormError.value = null;
  const body = {
    tier_name: tForm.tier_name.trim(), tier_order: tForm.tier_order,
    revenue_target: tForm.revenue_target, revenue_currency: tForm.revenue_currency.trim() || 'USD',
    min_certifications: tForm.min_certifications, min_headcount: tForm.min_headcount,
    requirements: tForm.requirements.trim() || null, benefits: tForm.benefits.trim() || null,
    is_current: tForm.is_current,
  };
  try {
    if (editingTier.value) {
      const res = await api<{ data: VendorPartnershipTier }>('PUT', `/api/v1/vendors/${vendorId}/tiers/${editingTier.value.id}`, { org: org(), body });
      const idx = tiers.value.findIndex(x => x.id === editingTier.value!.id);
      if (idx !== -1) tiers.value[idx] = res.data;
      if (body.is_current) tiers.value.forEach((t, i) => { if (i !== idx) t.is_current = false; });
    } else {
      const res = await api<{ data: VendorPartnershipTier }>('POST', `/api/v1/vendors/${vendorId}/tiers`, { org: org(), body });
      tiers.value.push(res.data);
      if (body.is_current) tiers.value.forEach(t => { if (t.id !== res.data.id) t.is_current = false; });
    }
    showTierForm.value = false;
  } catch (e: any) { tFormError.value = e?.message ?? 'Failed to save.'; }
  finally { saving.value = false; }
}

async function setCurrentTier(tier: VendorPartnershipTier) {
  await api('POST', `/api/v1/vendors/${vendorId}/tiers/${tier.id}/set-current`, { org: org() });
  tiers.value.forEach(t => { t.is_current = t.id === tier.id; });
}

async function deleteTier(tier: VendorPartnershipTier) {
  if (!confirm(`Delete tier "${tier.tier_name}"?`)) return;
  await api('DELETE', `/api/v1/vendors/${vendorId}/tiers/${tier.id}`, { org: org() });
  tiers.value = tiers.value.filter(t => t.id !== tier.id);
}

// ── Portal Users ──────────────────────────────────────────────────────────────

const portalUsers    = ref<VendorPortalUser[]>([]);
const portalLoading  = ref(false);
const showPortalForm = ref(false);
const editingPortalUser = ref<VendorPortalUser | null>(null);
const puFormError       = ref<string | null>(null);
const puForm = reactive({
  display_name: '', email: '', role: 'user' as PortalUserRole,
  portal_url: '', access_level: '', is_active: true,
});

async function loadPortalUsers() {
  portalLoading.value = true;
  try {
    const res = await api<{ data: VendorPortalUser[] }>('GET', `/api/v1/vendors/${vendorId}/portal-users`, { org: org() });
    portalUsers.value = res.data;
  } finally { portalLoading.value = false; }
}

function openAddPortalUser() {
  editingPortalUser.value = null;
  puForm.display_name = ''; puForm.email = ''; puForm.role = 'user';
  puForm.portal_url = ''; puForm.access_level = ''; puForm.is_active = true;
  puFormError.value = null;
  showPortalForm.value = true;
}

function openEditPortalUser(u: VendorPortalUser) {
  editingPortalUser.value = u;
  puForm.display_name = u.display_name; puForm.email = u.email; puForm.role = u.role;
  puForm.portal_url = u.portal_url ?? ''; puForm.access_level = u.access_level ?? '';
  puForm.is_active = u.is_active;
  puFormError.value = null;
  showPortalForm.value = true;
}

async function savePortalUser() {
  if (!puForm.display_name.trim()) { puFormError.value = 'Display name is required.'; return; }
  if (!puForm.email.trim()) { puFormError.value = 'Email is required.'; return; }
  saving.value = true; puFormError.value = null;
  const body = {
    display_name: puForm.display_name.trim(), email: puForm.email.trim(),
    role: puForm.role, portal_url: puForm.portal_url.trim() || null,
    access_level: puForm.access_level.trim() || null, is_active: puForm.is_active,
  };
  try {
    if (editingPortalUser.value) {
      const res = await api<{ data: VendorPortalUser }>('PUT', `/api/v1/vendors/${vendorId}/portal-users/${editingPortalUser.value.id}`, { org: org(), body });
      const idx = portalUsers.value.findIndex(x => x.id === editingPortalUser.value!.id);
      if (idx !== -1) portalUsers.value[idx] = res.data;
    } else {
      const res = await api<{ data: VendorPortalUser }>('POST', `/api/v1/vendors/${vendorId}/portal-users`, { org: org(), body });
      portalUsers.value.push(res.data);
    }
    showPortalForm.value = false;
  } catch (e: any) { puFormError.value = e?.message ?? 'Failed to save.'; }
  finally { saving.value = false; }
}

async function deletePortalUser(u: VendorPortalUser) {
  if (!confirm(`Remove portal user "${u.display_name}"?`)) return;
  await api('DELETE', `/api/v1/vendors/${vendorId}/portal-users/${u.id}`, { org: org() });
  portalUsers.value = portalUsers.value.filter(x => x.id !== u.id);
}

// ── Customers ─────────────────────────────────────────────────────────────────

const customers        = ref<VendorCustomer[]>([]);
const customerLoading  = ref(false);
const selectedCustomerId = ref<number | null>(null);
const showCustomerForm = ref(false);
const editingCustomer  = ref<VendorCustomer | null>(null);
const custFormError    = ref<string | null>(null);
const custForm = reactive({
  name: '', customer_type: 'enterprise' as CustomerType,
  industry: '', country: 'Kenya',
  contact_name: '', contact_email: '', contact_phone: '', notes: '', is_active: true,
});

async function loadCustomers() {
  customerLoading.value = true;
  try {
    const res = await api<{ data: VendorCustomer[] }>('GET', `/api/v1/vendors/${vendorId}/customers`, { org: org() });
    customers.value = res.data;
  } finally { customerLoading.value = false; }
}

function openAddCustomer() {
  editingCustomer.value = null;
  custForm.name = ''; custForm.customer_type = 'enterprise'; custForm.industry = '';
  custForm.country = 'Kenya'; custForm.contact_name = ''; custForm.contact_email = '';
  custForm.contact_phone = ''; custForm.notes = ''; custForm.is_active = true;
  custFormError.value = null;
  showCustomerForm.value = true;
}

function openEditCustomer(c: VendorCustomer) {
  editingCustomer.value = c;
  custForm.name = c.name; custForm.customer_type = c.customer_type; custForm.industry = c.industry ?? '';
  custForm.country = c.country; custForm.contact_name = c.contact_name ?? '';
  custForm.contact_email = c.contact_email ?? ''; custForm.contact_phone = c.contact_phone ?? '';
  custForm.notes = c.notes ?? ''; custForm.is_active = c.is_active;
  custFormError.value = null;
  showCustomerForm.value = true;
}

async function saveCustomer() {
  if (!custForm.name.trim()) { custFormError.value = 'Name is required.'; return; }
  saving.value = true; custFormError.value = null;
  const body = {
    name: custForm.name.trim(), customer_type: custForm.customer_type,
    industry: custForm.industry.trim() || null, country: custForm.country.trim() || 'Kenya',
    contact_name: custForm.contact_name.trim() || null, contact_email: custForm.contact_email.trim() || null,
    contact_phone: custForm.contact_phone.trim() || null, notes: custForm.notes.trim() || null,
    is_active: custForm.is_active,
  };
  try {
    if (editingCustomer.value) {
      const res = await api<{ data: VendorCustomer }>('PUT', `/api/v1/vendors/${vendorId}/customers/${editingCustomer.value.id}`, { org: org(), body });
      const idx = customers.value.findIndex(x => x.id === editingCustomer.value!.id);
      if (idx !== -1) customers.value[idx] = res.data;
    } else {
      const res = await api<{ data: VendorCustomer }>('POST', `/api/v1/vendors/${vendorId}/customers`, { org: org(), body });
      customers.value.push(res.data);
    }
    showCustomerForm.value = false;
  } catch (e: any) { custFormError.value = e?.message ?? 'Failed to save.'; }
  finally { saving.value = false; }
}

async function deleteCustomer(c: VendorCustomer) {
  if (!confirm(`Delete customer "${c.name}"?`)) return;
  await api('DELETE', `/api/v1/vendors/${vendorId}/customers/${c.id}`, { org: org() });
  customers.value = customers.value.filter(x => x.id !== c.id);
  if (selectedCustomerId.value === c.id) selectedCustomerId.value = null;
}

// ── Projects ──────────────────────────────────────────────────────────────────

const projects        = ref<VendorProject[]>([]);
const projectLoading  = ref(false);
const showProjectForm = ref(false);
const editingProject  = ref<VendorProject | null>(null);
const projFormError   = ref<string | null>(null);
const projForm = reactive({
  name: '', vendor_customer_id: null as number | null, project_code: '',
  status: 'active' as ProjectStatus, technology_space: '', technology_subcategory: '',
  description: '', start_date: '', end_date: '',
  contract_value: null as number | null, currency: 'KES', notes: '',
});

const filteredProjects = computed(() =>
  selectedCustomerId.value
    ? projects.value.filter(p => p.vendor_customer_id === selectedCustomerId.value)
    : projects.value
);

async function loadProjects() {
  projectLoading.value = true;
  try {
    const res = await api<{ data: VendorProject[] }>('GET', `/api/v1/vendors/${vendorId}/projects`, { org: org() });
    projects.value = res.data;
  } finally { projectLoading.value = false; }
}

function openAddProject() {
  editingProject.value = null;
  projForm.name = ''; projForm.vendor_customer_id = selectedCustomerId.value;
  projForm.project_code = ''; projForm.status = 'active';
  projForm.technology_space = ''; projForm.technology_subcategory = '';
  projForm.description = ''; projForm.start_date = ''; projForm.end_date = '';
  projForm.contract_value = null; projForm.currency = 'KES'; projForm.notes = '';
  projFormError.value = null;
  showProjectForm.value = true;
}

function openEditProject(p: VendorProject) {
  editingProject.value = p;
  projForm.name = p.name; projForm.vendor_customer_id = p.vendor_customer_id;
  projForm.project_code = p.project_code ?? ''; projForm.status = p.status;
  projForm.technology_space = p.technology_space ?? ''; projForm.technology_subcategory = p.technology_subcategory ?? '';
  projForm.description = p.description ?? ''; projForm.start_date = p.start_date ?? '';
  projForm.end_date = p.end_date ?? ''; projForm.contract_value = p.contract_value;
  projForm.currency = p.currency; projForm.notes = p.notes ?? '';
  projFormError.value = null;
  showProjectForm.value = true;
}

async function saveProject() {
  if (!projForm.name.trim()) { projFormError.value = 'Project name is required.'; return; }
  saving.value = true; projFormError.value = null;
  const body = {
    name: projForm.name.trim(), vendor_customer_id: projForm.vendor_customer_id,
    project_code: projForm.project_code.trim() || null, status: projForm.status,
    technology_space: projForm.technology_space || null,
    technology_subcategory: projForm.technology_subcategory || null,
    description: projForm.description.trim() || null,
    start_date: projForm.start_date || null, end_date: projForm.end_date || null,
    contract_value: projForm.contract_value, currency: projForm.currency.trim() || 'KES',
    notes: projForm.notes.trim() || null,
  };
  try {
    if (editingProject.value) {
      const res = await api<{ data: VendorProject }>('PUT', `/api/v1/vendors/${vendorId}/projects/${editingProject.value.id}`, { org: org(), body });
      const idx = projects.value.findIndex(x => x.id === editingProject.value!.id);
      if (idx !== -1) projects.value[idx] = res.data;
    } else {
      const res = await api<{ data: VendorProject }>('POST', `/api/v1/vendors/${vendorId}/projects`, { org: org(), body });
      projects.value.push(res.data);
    }
    showProjectForm.value = false;
  } catch (e: any) { projFormError.value = e?.message ?? 'Failed to save.'; }
  finally { saving.value = false; }
}

async function deleteProject(p: VendorProject) {
  if (!confirm(`Delete project "${p.name}"?`)) return;
  await api('DELETE', `/api/v1/vendors/${vendorId}/projects/${p.id}`, { org: org() });
  projects.value = projects.value.filter(x => x.id !== p.id);
}

// ── Init ─────────────────────────────────────────────────────────────────────

onMounted(() => {
  loadVendor();
});
</script>

<style scoped>
/* ── Profile header ──────────────────────────────────────────────────────────── */
.profile-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: var(--space-5);
}
.profile-header-left { display: flex; flex-direction: column; }

/* ── Tabs ──────────────────────────────────────────────────────────────────── */
.tab-nav {
  display: flex;
  gap: 0;
  border-bottom: 2px solid var(--color-border);
  margin-bottom: var(--space-5);
}
.tab-btn {
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  padding: var(--space-3) var(--space-5);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-muted);
  cursor: pointer;
  transition: color 0.15s, border-color 0.15s;
}
.tab-btn:hover { color: var(--color-text); }
.tab-btn.active {
  color: var(--color-brand-base);
  border-bottom-color: var(--color-brand-base);
  font-weight: var(--font-weight-semibold);
}
.tab-content { min-height: 200px; }

/* ── Overview layout ─────────────────────────────────────────────────────────── */
.overview-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-4);
}
.card-section-title {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border);
  padding-bottom: var(--space-2);
  margin-bottom: var(--space-4);
}

/* ── Detail grid ─────────────────────────────────────────────────────────────── */
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: var(--space-3); }
.detail-item { display: flex; flex-direction: column; gap: 2px; }
.detail-item--full { grid-column: 1/-1; }
.detail-key { font-size: var(--font-size-xs); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.detail-val { font-size: var(--font-size-sm); color: var(--color-text); }

/* ── Space chip ─────────────────────────────────────────────────────────────── */
.space-chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  border: 1px solid;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  white-space: nowrap;
}

/* ── Coverage grid ───────────────────────────────────────────────────────────── */
.coverage-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: var(--space-4);
}
.coverage-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-top: 3px solid;
  border-radius: var(--radius-md, 8px);
  padding: var(--space-4);
}
.coverage-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--space-3);
}
.coverage-space-name {
  font-weight: var(--font-weight-semibold);
  font-size: var(--font-size-sm);
}
.coverage-counters { display: flex; gap: var(--space-2); }
.coverage-subcats {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  min-height: 24px;
  margin-bottom: var(--space-3);
}
.coverage-subcat-chip {
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 500;
}
.chip--product { background: #3b82f620; color: #3b82f6; border: 1px solid #3b82f640; }
.chip--cert    { background: #10b98120; color: #10b981; border: 1px solid #10b98140; }
.chip--both    { background: #8b5cf620; color: #8b5cf6; border: 1px solid #8b5cf640; }
.coverage-legend {
  display: flex;
  gap: var(--space-2);
  padding-top: var(--space-2);
  border-top: 1px solid var(--color-border);
}

/* ── Tier grid ───────────────────────────────────────────────────────────────── */
.tier-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: var(--space-4);
}
.tier-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 8px);
  overflow: hidden;
}
.tier-card--current {
  border-color: var(--color-brand-base);
  box-shadow: 0 0 0 2px var(--color-brand-base)22;
}
.tier-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface);
}
.tier-card-body {
  padding: var(--space-4);
}
.tier-stat {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: var(--font-size-sm);
  padding: var(--space-1) 0;
  border-bottom: 1px dashed var(--color-border);
}
.row--selected { background: var(--color-brand-base)11; }

/* ── Drawer ──────────────────────────────────────────────────────────────────── */
.drawer-backdrop {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.4);
  z-index: 100;
  display: flex;
  justify-content: flex-end;
}
.drawer-panel {
  background: var(--color-surface);
  width: 100%;
  max-width: 560px;
  height: 100%;
  display: flex;
  flex-direction: column;
  box-shadow: -4px 0 24px rgba(0,0,0,0.18);
  animation: slideIn 0.25s ease;
}
@keyframes slideIn { from { transform: translateX(100%); } to { transform: translateX(0); } }
.drawer-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: var(--space-5) var(--space-6);
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;
}
.drawer-header h3 { margin: 0; font-size: var(--font-size-lg); font-weight: var(--font-weight-semibold); }
.drawer-body { flex: 1; overflow-y: auto; padding: var(--space-6); }
.drawer-footer {
  padding: var(--space-4) var(--space-6);
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
  flex-shrink: 0;
}
</style>
