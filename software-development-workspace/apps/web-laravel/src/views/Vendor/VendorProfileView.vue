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
            <span v-if="vendor.party_class === 'individual'">Individual · </span>
            {{ activeRoles.length ? activeRoles.map(r => roleLabel(r.role)).join(' · ') : typeLabel(vendor.vendor_type) }}
            <span v-if="vendor.category"> · {{ vendor.category }}</span>
          </p>
        </div>
        <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;justify-content:flex-end">
          <button class="btn btn-secondary btn-sm" @click="openEditVendor">Edit Profile</button>
          <button v-if="canManage && vendor.onboarding_status === 'draft'"
                  class="btn btn-primary btn-sm" @click="lifecycle('submit')">Submit for Review</button>
          <template v-if="canApprove && vendor.onboarding_status === 'pending_review'">
            <button class="btn btn-primary btn-sm" @click="lifecycle('approve')">
              Approve {{ nextStage ? `— ${nextStage.label}` : '' }}
            </button>
            <button class="btn btn-secondary btn-sm" @click="askReason('reject')">Reject</button>
          </template>
          <button v-if="canApprove && vendor.onboarding_status === 'approved'"
                  class="btn btn-secondary btn-sm" @click="askReason('suspend')">Suspend</button>
          <button v-if="canApprove && vendor.onboarding_status === 'suspended'"
                  class="btn btn-primary btn-sm" @click="lifecycle('reactivate')">Reactivate</button>
          <button v-if="canManage && vendor.onboarding_status === 'rejected'"
                  class="btn btn-secondary btn-sm" @click="lifecycle('return-to-draft')">Return to Draft</button>
        </div>
      </div>

      <!-- A change to banking or legal identity, waiting for somebody other
           than its author. The proposed values are deliberately not shown —
           the register would otherwise carry the new account number where
           anyone can read it, which is what the confirmation exists to stop. -->
      <div v-if="vendor.pending_change" class="card"
           style="border-left:3px solid var(--color-warning);padding:var(--space-3);margin-bottom:var(--space-4)">
        <strong>A sensitive change is waiting to be confirmed</strong>
        <p style="font-size:var(--font-size-sm);color:var(--color-text-muted);margin:var(--space-1) 0 var(--space-2)">
          {{ vendor.pending_change.fields.map(sensitiveLabel).join(', ') }}
          — proposed {{ (vendor.pending_change.proposed_at ?? '').slice(0, 16).replace('T', ' ') }}.
          It takes effect only when somebody other than whoever made it confirms.
        </p>
        <div v-if="canApprove" style="display:flex;gap:var(--space-2)">
          <button class="btn btn-primary btn-sm" @click="pendingChange('confirm')">Confirm change</button>
          <button class="btn btn-secondary btn-sm" @click="pendingChange('discard')">Discard</button>
        </div>
      </div>

      <!-- Why this supplier cannot be traded with yet. -->
      <div v-if="(vendor.activation_blockers ?? []).length" class="card"
           style="border-left:3px solid var(--color-error);padding:var(--space-3);margin-bottom:var(--space-4)">
        <strong>Not ready to trade with</strong>
        <ul style="margin:var(--space-2) 0 0;padding-left:var(--space-4)">
          <li v-for="b in vendor.activation_blockers" :key="b" style="font-size:var(--font-size-sm)">{{ b }}</li>
        </ul>
      </div>

      <!-- The chain, and who has signed. -->
      <div v-if="(vendor.approvals ?? []).length" class="card"
           style="padding:var(--space-3);margin-bottom:var(--space-4)">
        <div style="font-weight:var(--font-weight-semibold);margin-bottom:var(--space-2)">Onboarding review</div>
        <div style="display:flex;flex-wrap:wrap;gap:var(--space-3)">
          <div v-for="a in vendor.approvals" :key="a.id"
               style="display:flex;align-items:center;gap:var(--space-2)">
            <span class="badge" :class="{
              'badge-green': a.status === 'approved',
              'badge-red': a.status === 'rejected',
              'badge-gray': a.status === 'skipped',
              'badge-indigo': a.status === 'pending',
            }">{{ a.label }}</span>
            <span v-if="a.decided_at" style="font-size:var(--font-size-xs);color:var(--color-text-muted)">
              {{ a.status }} {{ a.decided_at.slice(0, 10) }}
            </span>
            <span v-else-if="a.status === 'pending'" style="font-size:var(--font-size-xs);color:var(--color-text-muted)">
              awaiting
            </span>
          </div>
        </div>
        <p v-if="vendor.rejection_reason" style="font-size:var(--font-size-sm);color:var(--color-error);margin:var(--space-2) 0 0">
          {{ vendor.rejection_reason }}
        </p>
      </div>

      <p v-if="lifecycleError" class="error" style="margin-bottom:var(--space-3)">{{ lifecycleError }}</p>

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
              <div class="detail-item"><span class="detail-key">Party</span><span class="detail-val">{{ vendor.party_class === 'individual' ? 'Individual' : 'Organisation' }}</span></div>
              <div class="detail-item"><span class="detail-key">Category</span><span class="detail-val">{{ vendor.category ?? '—' }}</span></div>
              <div v-if="vendor.description" class="detail-item detail-item--full">
                <span class="detail-key">Description</span>
                <span class="detail-val" style="white-space:pre-wrap">{{ vendor.description }}</span>
              </div>
            </div>
          </div>
          <!-- Whatever this party's roles ask about it. Empty for a party
               whose roles ask nothing, rather than an empty card. -->
          <div v-if="roleDetailRows.length" class="card">
            <div class="card-section-title">Role Details</div>
            <div class="detail-grid">
              <div v-for="row in roleDetailRows" :key="row.key" class="detail-item">
                <span class="detail-key">{{ row.label }}</span>
                <span class="detail-val">{{ row.value }}</span>
              </div>
            </div>
          </div>

          <!-- Roles: what this party does for us, and since when -->
          <div class="card">
            <div class="card-section-title" style="display:flex;align-items:center;justify-content:space-between;">
              <span>Roles</span>
              <button v-if="canManage" class="btn btn-secondary btn-sm" @click="showRoleForm = !showRoleForm">
                {{ showRoleForm ? 'Cancel' : '+ Add role' }}
              </button>
            </div>

            <div v-if="showRoleForm" style="display:flex;gap:var(--space-2);align-items:flex-end;margin-bottom:var(--space-3);flex-wrap:wrap;">
              <div class="form-group" style="margin:0;min-width:14rem;">
                <label>Role</label>
                <select v-model="roleForm.role">
                  <option value="">Choose…</option>
                  <option v-for="r in addableRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
                </select>
              </div>
              <div class="form-group" style="margin:0;">
                <label>In force from</label>
                <input v-model="roleForm.valid_from" type="date" />
              </div>
              <button class="btn btn-primary btn-sm" :disabled="!roleForm.role || savingRole" @click="addRole">
                {{ savingRole ? 'Saving…' : 'Add' }}
              </button>
            </div>

            <p v-if="roleError" class="error" style="margin-bottom:var(--space-2)">{{ roleError }}</p>

            <table class="table" style="width:100%">
              <thead><tr><th>Role</th><th>From</th><th>Until</th><th></th></tr></thead>
              <tbody>
                <tr v-for="r in (vendor.roles ?? [])" :key="r.id" :style="r.in_force ? '' : 'opacity:.55'">
                  <td>
                    <span class="badge" :class="r.role === 'oem' ? 'badge-indigo' : 'badge-gray'">{{ r.label }}</span>
                  </td>
                  <td>{{ r.valid_from ?? '—' }}</td>
                  <td>{{ r.valid_until ?? (r.in_force ? 'In force' : '—') }}</td>
                  <td style="text-align:right">
                    <button v-if="canManage && r.in_force" class="btn btn-secondary btn-sm" @click="endRole(r)">End</button>
                  </td>
                </tr>
                <tr v-if="!(vendor.roles ?? []).length">
                  <td colspan="4" style="color:var(--color-text-muted)">No roles recorded.</td>
                </tr>
              </tbody>
            </table>
            <p style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin:var(--space-2) 0 0;">
              Ending a role keeps its history — what a party was when a contract
              was signed stays answerable.
            </p>
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
              <div style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:wrap">
                <span class="badge badge-gray" style="font-size:11px">{{ tier.tier_order }}</span>
                <strong>{{ tier.tier_name }}</strong>
                <span v-if="tier.scope === 'product' && tier.product" class="badge badge-gray" style="font-size:11px">
                  {{ tier.product.name }}
                </span>
                <span v-if="tier.is_current" class="badge badge-green" style="font-size:11px">✓ Current</span>
                <!-- Whether we still hold what it demands, counted rather
                     than asserted. -->
                <template v-for="c in [complianceFor(tier.id)]" :key="tier.id">
                  <span v-if="c" class="badge" style="font-size:11px" :class="{
                    'badge-green': c.status === 'compliant',
                    'badge-amber': c.status === 'at_risk',
                    'badge-red': c.status === 'non_compliant',
                    'badge-gray': c.status === 'unmeasured',
                  }">{{ complianceLabel(c.status) }}</span>
                </template>
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
              <template v-for="c in [complianceFor(tier.id)]" :key="'c' + tier.id">
                <div v-if="c?.commercial" class="tier-stat">
                  <span class="detail-key">Seats</span>
                  <span :style="c.commercial.state === 'met' ? '' : 'color:var(--color-error)'">
                    {{ c.commercial.seats_activated }} of {{ c.commercial.seats_required }} activated
                  </span>
                </div>

                <!-- What it takes to hold this tier, and who holds it. -->
                <div v-if="c?.requirements?.length" style="margin-top:var(--space-3)">
                  <div class="detail-key" style="margin-bottom:4px">Required</div>
                  <div v-for="r in c.requirements" :key="r.id"
                       style="display:flex;align-items:center;justify-content:space-between;gap:var(--space-2);
                              font-size:var(--font-size-sm);padding:2px 0">
                    <span>
                      {{ r.label }}
                      <span class="badge badge-gray" style="font-size:10px;margin-left:4px">{{ r.type }}</span>
                    </span>
                    <span :style="r.state === 'met' ? '' : 'color:var(--color-error)'">
                      <template v-if="r.held === null">not measurable</template>
                      <template v-else>{{ r.held }} / {{ r.required }}</template>
                      <span v-if="r.expiring_soon" style="color:var(--color-warning)">
                        ({{ r.expiring_soon }} expiring)
                      </span>
                    </span>
                  </div>
                </div>

                <div v-if="c?.gaps?.length" style="margin-top:var(--space-2)">
                  <div v-for="g in c.gaps" :key="g"
                       style="font-size:var(--font-size-xs);color:var(--color-error)">{{ g }}</div>
                </div>
              </template>
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
      <!-- TAB: Contacts                                                           -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'contacts'" class="tab-content">
        <div class="toolbar">
          <span style="font-weight:var(--font-weight-semibold)">Contacts</span>
          <button v-if="canManage" class="btn btn-primary btn-sm" @click="openAddContact">+ Add Contact</button>
        </div>

        <p v-if="contactsLoading" class="status">Loading…</p>
        <div v-else-if="contacts.length === 0" class="card" style="text-align:center;padding:var(--space-8)">
          <p style="color:var(--color-text-muted)">No contacts recorded for this vendor yet.</p>
          <button v-if="canManage" class="btn btn-primary btn-sm" style="margin-top:var(--space-3)" @click="openAddContact">+ Add Contact</button>
        </div>
        <div v-else class="card table-wrap">
          <table>
            <thead><tr>
              <th>Name</th><th>Role at vendor</th><th>Category</th>
              <th>Email</th><th>Phone</th><th></th>
            </tr></thead>
            <tbody>
              <tr v-for="c in contacts" :key="c.id" :style="c.is_active ? '' : 'opacity:.55'">
                <td>
                  <strong>{{ c.full_name }}</strong>
                  <span v-if="c.is_primary" class="badge badge-indigo" style="margin-left:var(--space-1)">Primary</span>
                </td>
                <td>{{ c.designation ?? '—' }}</td>
                <td>{{ contactCategoryLabel(c.category) }}</td>
                <td>
                  <a v-if="c.email" :href="`mailto:${c.email}`">{{ c.email }}</a>
                  <span v-else>—</span>
                </td>
                <td>{{ c.phone ?? '—' }}</td>
                <td style="text-align:right;white-space:nowrap">
                  <button v-if="canManage" class="btn btn-secondary btn-sm" @click="openEditContact(c)">Edit</button>
                  <button v-if="canManage" class="btn btn-secondary btn-sm" style="margin-left:var(--space-1)" @click="deleteContact(c)">Remove</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="contactError" class="error" style="margin-top:var(--space-2)">{{ contactError }}</p>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <!-- TAB: Compliance Documents                                               -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'compliance'" class="tab-content">
        <div class="toolbar">
          <span style="font-weight:var(--font-weight-semibold)">Compliance Documents</span>
          <button v-if="canManage" class="btn btn-primary btn-sm" @click="openUpload()">+ Upload Document</button>
        </div>

        <p v-if="documentsLoading" class="status">Loading…</p>

        <template v-else>
          <!-- What stands between this party and being traded with. -->
          <div v-if="blockers.length" class="card"
               style="border-left:3px solid var(--color-error);padding:var(--space-3);margin-bottom:var(--space-4)">
            <strong>Not ready to trade with</strong>
            <ul style="margin:var(--space-2) 0 0;padding-left:var(--space-4)">
              <li v-for="b in blockers" :key="b" style="font-size:var(--font-size-sm)">{{ b }}</li>
            </ul>
          </div>
          <div v-else-if="checklist.length" class="card"
               style="border-left:3px solid var(--color-success);padding:var(--space-3);margin-bottom:var(--space-4)">
            <strong>All mandatory paperwork is in order</strong>
          </div>

          <!-- The checklist its roles produce. -->
          <div v-if="checklist.length" class="card table-wrap" style="margin-bottom:var(--space-4)">
            <table>
              <thead><tr>
                <th>Required document</th><th>Asked by</th><th>Status</th><th>Expires</th><th></th>
              </tr></thead>
              <tbody>
                <tr v-for="item in checklist" :key="item.document_key">
                  <td>
                    <strong>{{ item.label }}</strong>
                    <span v-if="item.requirement === 'optional'" class="badge badge-gray"
                          style="margin-left:var(--space-1)">Optional</span>
                    <div v-if="item.help" style="font-size:var(--font-size-xs);color:var(--color-text-muted)">
                      {{ item.help }}
                    </div>
                  </td>
                  <td style="font-size:var(--font-size-xs);color:var(--color-text-muted)">
                    {{ item.roles.map(roleLabel).join(', ') }}
                  </td>
                  <td>
                    <span class="badge" :class="{
                      'badge-green': item.state === 'held',
                      'badge-red': item.state === 'expired',
                      'badge-gray': item.state === 'missing',
                    }">{{ item.state === 'held' ? 'Held' : item.state === 'expired' ? 'Expired' : 'Missing' }}</span>
                  </td>
                  <td>
                    <span v-if="item.document?.is_perpetual">Never</span>
                    <span v-else-if="item.document?.expiry_date">
                      {{ item.document.expiry_date }}
                      <span v-if="item.document.days_left !== null && item.document.days_left <= 30"
                            style="font-size:var(--font-size-xs);color:var(--color-warning)">
                        ({{ item.document.days_left }} days)
                      </span>
                    </span>
                    <span v-else>—</span>
                  </td>
                  <td style="text-align:right">
                    <button v-if="canManage" class="btn btn-secondary btn-sm"
                            @click="openUpload(item)">{{ item.state === 'missing' ? 'Upload' : 'Replace' }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="status" style="margin-bottom:var(--space-4)">
            No requirements — this party holds no roles in force.
          </p>

          <!-- Everything on file, requirement-matching or not. -->
          <div v-if="documents.length" class="card table-wrap">
            <div style="padding:var(--space-3);font-weight:var(--font-weight-semibold)">On file</div>
            <table>
              <thead><tr><th>Document</th><th>Filed</th><th>Expires</th><th></th></tr></thead>
              <tbody>
                <tr v-for="d in documents" :key="d.id" :style="d.is_expired ? 'opacity:.6' : ''">
                  <td>
                    <strong>{{ d.label }}</strong>
                    <div style="font-size:var(--font-size-xs);color:var(--color-text-muted)">{{ d.original_name }}</div>
                  </td>
                  <td>{{ (d.uploaded_at ?? '').slice(0, 10) || '—' }}</td>
                  <td>
                    <span v-if="d.is_perpetual">Never</span>
                    <span v-else>{{ d.expiry_date ?? '—' }}</span>
                    <span v-if="d.is_expired" class="badge badge-red" style="margin-left:var(--space-1)">Expired</span>
                  </td>
                  <td style="text-align:right;white-space:nowrap">
                    <button class="btn btn-secondary btn-sm" @click="downloadDocument(d)">Download</button>
                    <button v-if="canManage" class="btn btn-secondary btn-sm"
                            style="margin-left:var(--space-1)" @click="removeDocument(d)">Remove</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <p v-if="documentError" class="error" style="margin-top:var(--space-2)">{{ documentError }}</p>
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

      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <!-- TAB: Performance & Payment                                             -->
      <!-- ══════════════════════════════════════════════════════════════════════ -->
      <div v-show="activeTab === 'scorecard'" class="tab-content">
        <div class="overview-grid">
          <!-- Scorecard -->
          <div class="card" style="grid-column:1/-1">
            <div class="card-section-title">
              Supplier Scorecard
              <span v-if="scorecard?.rating" class="sc-rating" :class="ratingTone(scorecard.rating)">
                {{ scorecard.overall }} / 100 · {{ scorecard.rating }}
              </span>
            </div>

            <p v-if="scorecardLoading" style="color:var(--color-text-muted)">Loading…</p>
            <p v-else-if="!scorecard || scorecard.volume.orders === 0" style="color:var(--color-text-muted)">
              Nothing has been ordered from this supplier yet, so there is no performance history to score.
            </p>

            <template v-else>
              <div class="sc-metrics">
                <div class="sc-metric">
                  <span class="sc-n">{{ pct(scorecard.delivery.on_time_rate) }}</span>
                  <span class="sc-l">On time</span>
                  <span class="sc-s">{{ scorecard.delivery.on_time_count }} on time · {{ scorecard.delivery.late_count }} late</span>
                </div>
                <div class="sc-metric">
                  <span class="sc-n">{{ pct(scorecard.quality.acceptance_rate) }}</span>
                  <span class="sc-l">Quality accepted</span>
                  <span class="sc-s">{{ scorecard.quality.accepted }} ✓ · {{ scorecard.quality.partial }} partial · {{ scorecard.quality.rejected }} ✗</span>
                </div>
                <div class="sc-metric">
                  <span class="sc-n">{{ days(scorecard.delivery.avg_lead_days) }}</span>
                  <span class="sc-l">Avg lead time</span>
                  <span class="sc-s">quoted {{ days(scorecard.delivery.quoted_lead_days) }}</span>
                </div>
                <div class="sc-metric">
                  <span class="sc-n">{{ pct(scorecard.commercial.price_competitiveness) }}</span>
                  <span class="sc-l">Price competitiveness</span>
                  <span class="sc-s">vs. rival quotes on the same rounds</span>
                </div>
                <div class="sc-metric">
                  <span class="sc-n">{{ pct(scorecard.commercial.win_rate) }}</span>
                  <span class="sc-l">Quote win rate</span>
                  <span class="sc-s">{{ scorecard.commercial.quotes_won }} of {{ scorecard.commercial.quotes_given }}</span>
                </div>
                <div class="sc-metric">
                  <span class="sc-n">{{ pct(scorecard.responsiveness.acceptance_rate) }}</span>
                  <span class="sc-l">Order acceptance</span>
                  <span class="sc-s">{{ scorecard.responsiveness.declined_orders }} declined</span>
                </div>
              </div>

              <div class="detail-grid" style="margin-top:var(--space-4)">
                <div class="detail-item"><span class="detail-key">Orders placed</span><span class="detail-val">{{ scorecard.volume.orders }} ({{ scorecard.volume.open_orders }} open)</span></div>
                <div class="detail-item"><span class="detail-key">Deliveries received</span><span class="detail-val">{{ scorecard.volume.deliveries }}</span></div>
                <div class="detail-item"><span class="detail-key">Order value</span><span class="detail-val">{{ scorecard.volume.currency }} {{ scorecard.volume.order_value.toLocaleString() }}</span></div>
                <div class="detail-item"><span class="detail-key">Paid to date</span><span class="detail-val">{{ scorecard.volume.currency }} {{ scorecard.volume.spend.toLocaleString() }}</span></div>
                <div class="detail-item"><span class="detail-key">Invoice exceptions</span><span class="detail-val">{{ scorecard.commercial.invoice_exceptions }}</span></div>
                <div class="detail-item"><span class="detail-key">Avg time to accept</span><span class="detail-val">{{ scorecard.responsiveness.avg_accept_hours === null ? '—' : scorecard.responsiveness.avg_accept_hours + ' h' }}</span></div>
              </div>
            </template>
          </div>

          <!-- Remittance -->
          <div class="card" style="grid-column:1/-1">
            <div class="card-section-title">
              Banking & Payment Terms
              <button v-if="!payEdit" class="btn-secondary btn-sm" style="margin-left:auto" @click="startPayEdit">Edit</button>
            </div>

            <p v-if="payError" style="color:var(--color-danger-text);font-size:var(--font-size-xs)">{{ payError }}</p>

            <div v-if="!payEdit" class="detail-grid">
              <div class="detail-item"><span class="detail-key">Bank</span><span class="detail-val">{{ vendor.bank_name ?? '—' }}</span></div>
              <div class="detail-item"><span class="detail-key">Branch</span><span class="detail-val">{{ vendor.bank_branch ?? '—' }}</span></div>
              <div class="detail-item"><span class="detail-key">Account Name</span><span class="detail-val">{{ vendor.account_name ?? '—' }}</span></div>
              <div class="detail-item"><span class="detail-key">Account Number</span><span class="detail-val">{{ vendor.account_number ?? '—' }}</span></div>
              <div class="detail-item"><span class="detail-key">SWIFT</span><span class="detail-val">{{ vendor.swift_code ?? '—' }}</span></div>
              <div class="detail-item">
                <span class="detail-key">Payment Terms</span>
                <span class="detail-val">{{ vendor.payment_terms_days === null ? '—' : vendor.payment_terms_days + ' days' }}</span>
              </div>
              <div class="detail-item">
                <span class="detail-key">Credit Limit</span>
                <span class="detail-val">{{ vendor.credit_limit === null ? '—' : (vendor.credit_limit_currency ?? '') + ' ' + vendor.credit_limit.toLocaleString() }}</span>
              </div>
            </div>

            <div v-else class="detail-grid">
              <label class="detail-item"><span class="detail-key">Bank</span><input v-model="payForm.bank_name" class="control" /></label>
              <label class="detail-item"><span class="detail-key">Branch</span><input v-model="payForm.bank_branch" class="control" /></label>
              <label class="detail-item"><span class="detail-key">Account Name</span><input v-model="payForm.account_name" class="control" /></label>
              <label class="detail-item"><span class="detail-key">Account Number</span><input v-model="payForm.account_number" class="control" /></label>
              <label class="detail-item"><span class="detail-key">SWIFT</span><input v-model="payForm.swift_code" class="control" /></label>
              <label class="detail-item">
                <span class="detail-key">Payment Terms (days)</span>
                <input v-model="payForm.payment_terms_days" class="control" type="number" min="0" max="365" />
              </label>
              <label class="detail-item"><span class="detail-key">Credit Limit</span><input v-model="payForm.credit_limit" class="control" type="number" min="0" /></label>
              <label class="detail-item"><span class="detail-key">Credit Currency</span><input v-model="payForm.credit_limit_currency" class="control" maxlength="3" placeholder="KES" /></label>
              <div class="detail-item detail-item--full" style="flex-direction:row;gap:var(--space-2)">
                <button class="btn-primary btn-sm" @click="savePayDetails">Save</button>
                <button class="btn-secondary btn-sm" @click="payEdit = false">Cancel</button>
              </div>
            </div>

            <p style="margin-top:var(--space-3);font-size:var(--font-size-xs);color:var(--color-text-muted)">
              Payment terms set the due date on every invoice recorded against this supplier.
            </p>
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
          <!-- What the tier is held on. A programme is rarely one badge for
               the whole relationship: Gold on firewalls and Silver on
               switches at the same time is the ordinary case. -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>This tier covers <span style="color:var(--color-error)">*</span></label>
              <select v-model="tForm.scope">
                <option value="general">The partnership generally</option>
                <option value="product">One product</option>
              </select>
            </div>
            <div v-if="tForm.scope === 'product'" class="form-group">
              <label>Product <span style="color:var(--color-error)">*</span></label>
              <select v-model.number="tForm.vendor_product_id">
                <option :value="null">Choose…</option>
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
              </select>
              <p v-if="!products.length" style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin:var(--space-1) 0 0">
                No products recorded yet — add one on the Products tab first.
              </p>
            </div>
          </div>

          <!-- ── What it takes to hold it ─────────────────────────────── -->
          <div style="border-top:1px solid var(--color-border);margin:var(--space-4) 0 var(--space-3);padding-top:var(--space-3)">
            <div style="display:flex;align-items:center;justify-content:space-between">
              <div>
                <span style="font-weight:var(--font-weight-semibold)">Required training, assessments &amp; certifications</span>
                <p style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin:var(--space-1) 0 0">
                  Named, so compliance can be counted rather than asked for. Each
                  one says how many of our people must hold it.
                </p>
              </div>
              <button class="btn btn-secondary btn-sm" @click="addTierRequirement">+ Add</button>
            </div>
          </div>

          <div v-for="(req, i) in tForm.tier_requirements" :key="i"
               style="display:grid;grid-template-columns:8rem 1fr 6rem 2rem;gap:var(--space-2);align-items:end;margin-bottom:var(--space-2)">
            <div class="form-group" style="margin:0">
              <label v-if="i === 0">Kind</label>
              <select v-model="req.requirement_type">
                <option value="training">Training</option>
                <option value="assessment">Assessment</option>
                <option value="certification">Certification</option>
              </select>
            </div>
            <div class="form-group" style="margin:0">
              <label v-if="i === 0">What</label>
              <select v-model.number="req.training_item_id">
                <option :value="null">Choose…</option>
                <option v-for="t in trainingItems" :key="t.id" :value="t.id">{{ t.title }}</option>
              </select>
            </div>
            <div class="form-group" style="margin:0">
              <label v-if="i === 0">People</label>
              <input v-model.number="req.required_headcount" type="number" min="1" />
            </div>
            <button class="btn btn-ghost btn-sm" style="color:var(--color-error)"
                    @click="tForm.tier_requirements.splice(i, 1)">✕</button>
          </div>
          <p v-if="!tForm.tier_requirements.length" style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin:0 0 var(--space-3)">
            Nothing required yet — this tier cannot be measured until something is.
          </p>

          <!-- ── The commercial half ──────────────────────────────────── -->
          <div style="border-top:1px solid var(--color-border);margin:var(--space-4) 0 var(--space-3);padding-top:var(--space-3)">
            <span style="font-weight:var(--font-weight-semibold)">Commercial commitment</span>
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

          <!-- Seats appear with the money, because the money cannot be
               checked without them: "half a million dollars" is a sentence,
               "half a million covering 250 activated seats" is a status. -->
          <div v-if="tierHasMoney" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
            <div class="form-group">
              <label>Seats Required <span style="color:var(--color-error)">*</span></label>
              <input v-model.number="tForm.seats_required" type="number" min="0" />
              <p style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin:var(--space-1) 0 0">
                How many activated seats the commitment covers.
              </p>
            </div>
            <div class="form-group">
              <label>Seats Activated</label>
              <input v-model.number="tForm.seats_activated" type="number" min="0" />
            </div>
          </div>

          <div class="form-group">
            <label>Notes on requirements <span style="color:var(--color-text-muted)">(free text)</span></label>
            <textarea v-model="tForm.requirements" rows="3" placeholder="Anything the list above cannot express…" />
          </div>
          <div class="form-group">
            <label>Benefits</label>
            <textarea v-model="tForm.benefits" rows="4" placeholder="Benefits at this tier level…" />
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:var(--space-3)">
            <input id="tCurrent" type="checkbox" v-model="tForm.is_current" style="width:auto" />
            <label for="tCurrent" style="margin:0;cursor:pointer">Currently held</label>
          </div>
          <p style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin:0">
            Marking this current stands down any other tier held on the same
            {{ tForm.scope === 'product' ? 'product' : 'partnership' }} — not the others.
          </p>
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
    <div v-if="showUpload" class="drawer-backdrop" @click.self="showUpload = false">
      <div class="drawer-panel">
        <div class="drawer-header">
          <h3>Upload Document</h3>
          <button class="btn btn-ghost btn-sm" @click="showUpload = false">✕</button>
        </div>
        <div class="drawer-body">
          <div class="form-group">
            <label>Document <span style="color:var(--color-error)">*</span></label>
            <select v-model="uploadForm.document_key" :disabled="!!uploadFor">
              <option value="">Choose…</option>
              <option v-for="item in checklist" :key="item.document_key" :value="item.document_key">
                {{ item.label }}
              </option>
              <option value="other">Something else</option>
            </select>
          </div>
          <div v-if="uploadForm.document_key === 'other'" class="form-group">
            <label>Name it <span style="color:var(--color-error)">*</span></label>
            <input v-model="uploadForm.label" type="text" placeholder="e.g. ISO 9001 Certificate" />
          </div>
          <div class="form-group">
            <label>File <span style="color:var(--color-error)">*</span></label>
            <input ref="uploadInput" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" />
            <p style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin:var(--space-1) 0 0">
              Stored encrypted; it can only be read back through this screen.
            </p>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:var(--space-2);font-weight:normal">
              <input v-model="uploadForm.is_perpetual" type="checkbox" style="width:auto" />
              This document never expires
            </label>
          </div>
          <div v-if="!uploadForm.is_perpetual" class="form-group">
            <label>Expires on <span style="color:var(--color-error)">*</span></label>
            <input v-model="uploadForm.expiry_date" type="date" />
            <p style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin:var(--space-1) 0 0">
              Warnings start six months out and step up as the date approaches.
            </p>
          </div>
          <div class="form-group">
            <label>Issued on</label>
            <input v-model="uploadForm.issue_date" type="date" />
          </div>
          <p v-if="uploadError" class="error">{{ uploadError }}</p>
        </div>
        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="showUpload = false">Cancel</button>
          <button class="btn btn-primary" :disabled="uploading" @click="submitUpload">
            {{ uploading ? 'Uploading…' : 'Upload' }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="showContactForm" class="drawer-backdrop" @click.self="showContactForm = false">
      <div class="drawer-panel">
        <div class="drawer-header">
          <h3>{{ editingContact ? 'Edit Contact' : 'Add Contact' }}</h3>
          <button class="btn btn-ghost btn-sm" @click="showContactForm = false">✕</button>
        </div>
        <div class="drawer-body">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
            <div class="form-group">
              <label>First Name <span style="color:var(--color-error)">*</span></label>
              <input v-model="contactForm.first_name" type="text" placeholder="e.g. Grace" />
            </div>
            <div class="form-group">
              <label>Last Name</label>
              <input v-model="contactForm.last_name" type="text" placeholder="e.g. Mwende" />
            </div>
          </div>
          <div class="form-group">
            <label>Category</label>
            <select v-model="contactForm.category">
              <option v-for="c in CONTACT_CATEGORIES" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
            <p style="font-size:var(--font-size-xs);color:var(--color-text-muted);margin:var(--space-1) 0 0;">
              Naming a new primary stands the current one down — two people to
              ring is the same as none.
            </p>
          </div>
          <div class="form-group">
            <label>Role at vendor</label>
            <input v-model="contactForm.designation" type="text" placeholder="e.g. Account Manager" />
          </div>
          <div class="form-group">
            <label>Email</label>
            <input v-model="contactForm.email" type="email" placeholder="e.g. grace@acme.co.ke" />
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-3)">
            <div class="form-group">
              <label>Phone</label>
              <input v-model="contactForm.phone" type="text" placeholder="+254 …" />
            </div>
            <div class="form-group">
              <label>Alternate Phone</label>
              <input v-model="contactForm.alternate_phone" type="text" />
            </div>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:var(--space-2);font-weight:normal">
              <input v-model="contactForm.is_active" type="checkbox" style="width:auto" />
              Still at this vendor
            </label>
          </div>
          <p v-if="contactFormError" class="error">{{ contactFormError }}</p>
        </div>
        <div class="drawer-footer">
          <button class="btn btn-secondary" @click="showContactForm = false">Cancel</button>
          <button class="btn btn-primary" :disabled="!contactForm.first_name.trim() || savingContact" @click="saveContact">
            {{ savingContact ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </div>
    </div>

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
import { currentOrgSlug, getToken } from '@/stores/auth';
import { TECH_SPACES, spaceLabel, subcategoryLabel, subcategoriesFor, spaceColor } from '@/lib/tech-spaces';
import type {
  Vendor, VendorOnboardingStatus, VendorType, ProductType,
  VendorProduct, IncentiveType, ValueType, VendorIncentive,
  CertLevel, VendorCertification, TrainingItem,
  VendorPartnershipTier, VendorPortalUser, PortalUserRole,
  VendorCustomer, CustomerType, VendorProject, ProjectStatus,
  StakeholderRole, StakeholderRoleName, RoleField,
} from '@/lib/types';
import { STAKEHOLDER_ROLES } from '@/lib/types';
import { can } from '@/stores/permissions';

// ── Setup ────────────────────────────────────────────────────────────────────

const route  = useRoute();
const router = useRouter();

function org(): string | null { return currentOrgSlug.value; }

const vendorId = Number(route.params.id);

// ── Tabs ─────────────────────────────────────────────────────────────────────

const TABS = [
  { id: 'overview',       label: 'Overview' },
  { id: 'contacts',       label: 'Contacts' },
  { id: 'compliance',     label: 'Compliance Documents' },
  { id: 'products',       label: 'Products & Solutions' },
  { id: 'incentives',     label: 'Incentives & NFRs' },
  { id: 'certifications', label: 'Training & Certifications' },
  { id: 'partnership',    label: 'Partnership' },
  { id: 'portal_users',  label: 'Portal Users' },
  { id: 'customers',     label: 'Customers & Projects' },
  { id: 'coverage',       label: 'Technology Coverage' },
  { id: 'scorecard',      label: 'Performance & Payment' },
] as const;

type TabId = typeof TABS[number]['id'];

const activeTab   = ref<TabId>('overview');
const loadedTabs  = new Set<TabId>(['overview']);

function switchTab(id: TabId) {
  activeTab.value = id;
  if (!loadedTabs.has(id)) {
    loadedTabs.add(id);
    if (id === 'contacts')       loadContacts();
    if (id === 'compliance')     loadDocuments();
    if (id === 'products')       loadProducts();
    if (id === 'incentives')     loadIncentives();
    if (id === 'certifications') { loadCerts(); loadTrainingItems(); }
    if (id === 'partnership')    loadTiers();
    if (id === 'portal_users')   loadPortalUsers();
    if (id === 'customers')      { loadCustomers(); loadProjects(); }
    if (id === 'scorecard')      loadScorecard();
  }
}

// ── Performance scorecard & remittance ───────────────────────────────────────
// Every figure here is computed from orders, deliveries and quotes at the
// moment it is asked for. A metric with nothing behind it comes back null and
// is shown as "no data" rather than as a zero, so a supplier nobody has
// ordered from yet is not displayed as one that always delivers late.

interface Scorecard {
  vendor: { id: number; name: string };
  volume: { orders: number; deliveries: number; order_value: number; spend: number; open_orders: number; currency: string };
  delivery: { on_time_rate: number | null; on_time_count: number; late_count: number; avg_lead_days: number | null; quoted_lead_days: number | null };
  quality: { acceptance_rate: number | null; accepted: number; partial: number; rejected: number };
  commercial: { price_competitiveness: number | null; quotes_given: number; quotes_won: number; win_rate: number | null; invoice_exceptions: number };
  responsiveness: { acceptance_rate: number | null; declined_orders: number; avg_accept_hours: number | null };
  overall: number | null;
  rating: string | null;
}

const scorecard = ref<Scorecard | null>(null);
const scorecardLoading = ref(false);

async function loadScorecard() {
  scorecardLoading.value = true;
  try {
    scorecard.value = (await api<{ data: Scorecard }>('GET', `/api/v1/procurement/vendors/${vendorId}/scorecard`, { org: org() })).data;
  } catch { /* the panel shows its empty state */ }
  finally { scorecardLoading.value = false; }
}

const pct = (v: number | null) => v === null ? '—' : `${v}%`;
const days = (v: number | null) => v === null ? '—' : `${v} d`;
const ratingTone = (r: string | null) =>
  r === 'excellent' ? 'ok' : r === 'good' ? 'ok' : r === 'fair' ? 'warn' : r === 'poor' ? 'bad' : 'muted';

// Remittance details are edited in place — they change far more often than
// the rest of a vendor profile, and are needed the moment an invoice lands.
const payEdit = ref(false);
const payError = ref('');
const payForm = ref({
  bank_name: '', bank_branch: '', account_name: '', account_number: '',
  swift_code: '', payment_terms_days: '' as number | string,
  credit_limit: '' as number | string, credit_limit_currency: '',
});

function startPayEdit() {
  const v = vendor.value;
  if (!v) return;
  payForm.value = {
    bank_name: v.bank_name ?? '', bank_branch: v.bank_branch ?? '',
    account_name: v.account_name ?? '', account_number: v.account_number ?? '',
    swift_code: v.swift_code ?? '', payment_terms_days: v.payment_terms_days ?? '',
    credit_limit: v.credit_limit ?? '', credit_limit_currency: v.credit_limit_currency ?? '',
  };
  payError.value = '';
  payEdit.value = true;
}

async function savePayDetails() {
  const f = payForm.value;
  payError.value = '';
  try {
    const res = await api<{ data: Vendor }>('PUT', `/api/v1/vendors/${vendorId}`, {
      org: org(), body: {
        bank_name: f.bank_name || null, bank_branch: f.bank_branch || null,
        account_name: f.account_name || null, account_number: f.account_number || null,
        swift_code: f.swift_code || null,
        payment_terms_days: f.payment_terms_days === '' ? null : Number(f.payment_terms_days),
        credit_limit: f.credit_limit === '' ? null : Number(f.credit_limit),
        credit_limit_currency: f.credit_limit_currency || null,
      },
    });
    vendor.value = res.data;
    payEdit.value = false;
  } catch (e: any) {
    payError.value = e?.message ?? 'Could not save the payment details.';
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
    void loadRoleFields();
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

// ── Role details ─────────────────────────────────────────────────────────────

const roleFields = ref<RoleField[]>([]);

/**
 * Answers paired with the questions that produced them.
 *
 * Driven by the definitions rather than the stored keys, so a raw
 * `oems_represented` never reaches the screen — and an answer whose question
 * has since been retired is simply not shown, while remaining on the record.
 */
const roleDetailRows = computed(() => {
  const answers = vendor.value?.role_details ?? {};

  return roleFields.value
    .filter(f => (answers[f.key] ?? '') !== '')
    .map(f => ({
      key: f.key,
      label: f.label,
      value: f.type === 'boolean'
        ? (answers[f.key] ? 'Yes' : 'No')
        : String(answers[f.key]),
    }));
});

async function loadRoleFields(): Promise<void> {
  if (!vendor.value) return;
  try {
    const res = await api<{ data: RoleField[] }>('GET', '/api/v1/vendors/role-fields', {
      org: org(),
      params: {
        roles: activeRoles.value.map(r => r.role).join(','),
        party_class: vendor.value.party_class ?? 'organization',
      },
    });
    roleFields.value = res.data;
  } catch { roleFields.value = []; }
}

// ── Onboarding lifecycle ─────────────────────────────────────────────────────

const canApprove     = computed(() => can('vendors.approve'));
const lifecycleError = ref('');

/** The stage the chain is waiting on, for the button's label. */
const nextStage = computed(() => (vendor.value?.approvals ?? []).find(a => a.status === 'pending') ?? null);

const SENSITIVE_LABELS: Record<string, string> = {
  name: 'Legal name', registration_number: 'Registration number', tax_id: 'Tax ID',
  bank_name: 'Bank', bank_branch: 'Branch', account_name: 'Account name',
  account_number: 'Account number', swift_code: 'SWIFT code',
};

function sensitiveLabel(field: string): string {
  return SENSITIVE_LABELS[field] ?? field.replace(/_/g, ' ');
}

async function lifecycle(action: string, body: Record<string, unknown> = {}): Promise<void> {
  lifecycleError.value = '';
  try {
    const res = await api<{ data: Vendor }>('POST', `/api/v1/vendors/${vendorId}/${action}`, { org: org(), body });
    vendor.value = res.data;
  } catch (e) {
    // The API refuses with the reason — "a vendor that is draft cannot become
    // approved", "Tax Compliance Certificate expired on …" — and that sentence
    // is exactly what the reviewer needs to read.
    lifecycleError.value = e instanceof Error ? e.message : 'That could not be done.';
  }
}

function askReason(action: 'reject' | 'suspend'): void {
  const reason = window.prompt(action === 'reject'
    ? 'Why is this vendor being rejected?'
    : 'Why is this vendor being suspended?');
  if (!reason?.trim()) return;
  void lifecycle(action, { reason: reason.trim() });
}

async function pendingChange(action: 'confirm' | 'discard'): Promise<void> {
  if (action === 'discard' && !window.confirm('Discard the proposed change?')) return;
  await lifecycle(`pending-change/${action}`);
}

// ── Compliance documents ─────────────────────────────────────────────────────

interface ChecklistItem {
  document_key: string; label: string; requirement: 'mandatory' | 'optional';
  help: string | null; roles: string[]; state: 'held' | 'expired' | 'missing';
  document: { id: number; label: string; expiry_date: string | null; is_perpetual: boolean; days_left: number | null } | null;
}

interface VendorDocumentRow {
  id: number; document_key: string; label: string; original_name: string;
  expiry_date: string | null; is_perpetual: boolean; is_expired: boolean;
  days_left: number | null; uploaded_at: string | null;
}

const documents        = ref<VendorDocumentRow[]>([]);
const checklist        = ref<ChecklistItem[]>([]);
const blockers         = ref<string[]>([]);
const documentsLoading = ref(false);
const documentError    = ref('');

const showUpload  = ref(false);
const uploading   = ref(false);
const uploadError = ref('');
const uploadInput = ref<HTMLInputElement | null>(null);
const uploadFor   = ref<ChecklistItem | null>(null);
const uploadForm  = reactive({
  document_key: '', label: '', expiry_date: '', issue_date: '', is_perpetual: false,
});

async function loadDocuments(): Promise<void> {
  documentsLoading.value = true; documentError.value = '';
  try {
    const res = await api<{ data: VendorDocumentRow[]; meta: { checklist: ChecklistItem[]; blockers: string[] } }>(
      'GET', `/api/v1/vendors/${vendorId}/documents`, { org: org() });
    documents.value = res.data;
    checklist.value = res.meta.checklist;
    blockers.value  = res.meta.blockers;
  } catch (e) {
    documentError.value = e instanceof Error ? e.message : 'Could not load documents.';
  } finally { documentsLoading.value = false; }
}

/** Opened from a checklist row, the document is already chosen. */
function openUpload(item?: ChecklistItem): void {
  uploadFor.value = item ?? null;
  uploadForm.document_key = item?.document_key ?? '';
  uploadForm.label = item?.label ?? '';
  uploadForm.expiry_date = ''; uploadForm.issue_date = '';
  uploadForm.is_perpetual = false;
  uploadError.value = '';
  showUpload.value = true;
}

async function submitUpload(): Promise<void> {
  const file = uploadInput.value?.files?.[0];
  if (!file) { uploadError.value = 'Choose a file to upload.'; return; }
  if (!uploadForm.document_key) { uploadError.value = 'Say which document this is.'; return; }
  if (!uploadForm.is_perpetual && !uploadForm.expiry_date) {
    uploadError.value = 'Give an expiry date, or tick that it never expires.'; return;
  }

  uploading.value = true; uploadError.value = '';
  const label = uploadForm.document_key === 'other'
    ? uploadForm.label.trim()
    : (checklist.value.find(c => c.document_key === uploadForm.document_key)?.label
        ?? (uploadForm.label.trim() || file.name));

  const form = new FormData();
  form.append('file', file);
  form.append('document_key', uploadForm.document_key === 'other' ? 'other' : uploadForm.document_key);
  form.append('label', label);
  if (uploadForm.is_perpetual) form.append('is_perpetual', '1');
  else form.append('expiry_date', uploadForm.expiry_date);
  if (uploadForm.issue_date) form.append('issue_date', uploadForm.issue_date);

  try {
    await api('POST', `/api/v1/vendors/${vendorId}/documents`, { org: org(), formData: form });
    showUpload.value = false;
    await Promise.all([loadDocuments(), loadVendor()]);
  } catch (e) {
    uploadError.value = e instanceof Error ? e.message : 'Upload failed.';
  } finally { uploading.value = false; }
}

/**
 * Downloads carry the bearer token, so the file is fetched and handed to the
 * browser as a blob — a plain link would arrive unauthenticated.
 */
async function downloadDocument(d: VendorDocumentRow): Promise<void> {
  try {
    // Same shape as the employee-document download: fetched with the bearer
    // token and handed over as a blob, because a plain <a href> carries none.
    const base = (import.meta.env.VITE_LARAVEL_API_URL ?? '').replace(/\/$/, '');
    const headers: Record<string, string> = {};
    const token = getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
    const slug = org();
    if (slug) headers['X-Organization'] = slug;

    const res = await fetch(`${base}/api/v1/vendors/${vendorId}/documents/${d.id}/download`, { headers });
    if (!res.ok) throw new Error(`Download failed (${res.status}).`);

    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement('a');
    a.href = url; a.download = d.original_name;
    a.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    documentError.value = e instanceof Error ? e.message : 'Could not download the document.';
  }
}

async function removeDocument(d: VendorDocumentRow): Promise<void> {
  if (!window.confirm(`Remove "${d.label}"? It stays in the audit record.`)) return;
  try {
    await api('DELETE', `/api/v1/vendors/${vendorId}/documents/${d.id}`, { org: org() });
    await Promise.all([loadDocuments(), loadVendor()]);
  } catch (e) {
    documentError.value = e instanceof Error ? e.message : 'Could not remove the document.';
  }
}

// ── Contacts ─────────────────────────────────────────────────────────────────

interface VendorContactRow {
  id: number; category: string; title: string | null;
  first_name: string; last_name: string | null; full_name: string;
  designation: string | null; department: string | null;
  email: string | null; phone: string | null; alternate_phone: string | null;
  is_primary: boolean; is_active: boolean;
}

const CONTACT_CATEGORIES = [
  { value: 'primary',   label: 'Primary' },
  { value: 'secondary', label: 'Secondary' },
  { value: 'sales',     label: 'Sales' },
  { value: 'technical', label: 'Technical' },
  { value: 'support',   label: 'Support' },
  { value: 'finance',   label: 'Finance' },
  { value: 'other',     label: 'Other' },
];

const contacts         = ref<VendorContactRow[]>([]);
const contactsLoading  = ref(false);
const contactError     = ref('');
const showContactForm  = ref(false);
const editingContact   = ref<VendorContactRow | null>(null);
const savingContact    = ref(false);
const contactFormError = ref('');
const contactForm = reactive({
  first_name: '', last_name: '', category: 'other', designation: '',
  email: '', phone: '', alternate_phone: '', is_active: true,
});

function contactCategoryLabel(v: string): string {
  return CONTACT_CATEGORIES.find(c => c.value === v)?.label ?? v;
}

async function loadContacts(): Promise<void> {
  contactsLoading.value = true; contactError.value = '';
  try {
    const res = await api<{ data: VendorContactRow[] }>('GET', `/api/v1/vendors/${vendorId}/contacts`, { org: org() });
    contacts.value = res.data;
  } catch (e) {
    contactError.value = e instanceof Error ? e.message : 'Could not load contacts.';
  } finally { contactsLoading.value = false; }
}

function resetContactForm(): void {
  contactForm.first_name = ''; contactForm.last_name = ''; contactForm.category = 'other';
  contactForm.designation = ''; contactForm.email = ''; contactForm.phone = '';
  contactForm.alternate_phone = ''; contactForm.is_active = true;
  contactFormError.value = '';
}

function openAddContact(): void {
  editingContact.value = null;
  resetContactForm();
  showContactForm.value = true;
}

function openEditContact(c: VendorContactRow): void {
  editingContact.value = c;
  contactForm.first_name = c.first_name; contactForm.last_name = c.last_name ?? '';
  contactForm.category = c.category; contactForm.designation = c.designation ?? '';
  contactForm.email = c.email ?? ''; contactForm.phone = c.phone ?? '';
  contactForm.alternate_phone = c.alternate_phone ?? ''; contactForm.is_active = c.is_active;
  contactFormError.value = '';
  showContactForm.value = true;
}

async function saveContact(): Promise<void> {
  savingContact.value = true; contactFormError.value = '';
  const body = {
    first_name: contactForm.first_name.trim(),
    last_name: contactForm.last_name.trim() || null,
    category: contactForm.category,
    designation: contactForm.designation.trim() || null,
    email: contactForm.email.trim() || null,
    phone: contactForm.phone.trim() || null,
    alternate_phone: contactForm.alternate_phone.trim() || null,
    is_active: contactForm.is_active,
  };
  try {
    if (editingContact.value) {
      await api('PUT', `/api/v1/vendors/${vendorId}/contacts/${editingContact.value.id}`, { org: org(), body });
    } else {
      await api('POST', `/api/v1/vendors/${vendorId}/contacts`, { org: org(), body });
    }
    showContactForm.value = false;
    await Promise.all([loadContacts(), loadVendor()]);
  } catch (e) {
    contactFormError.value = e instanceof Error ? e.message : 'Could not save the contact.';
  } finally { savingContact.value = false; }
}

async function deleteContact(c: VendorContactRow): Promise<void> {
  if (!window.confirm(`Remove ${c.full_name} from this vendor?`)) return;
  try {
    await api('DELETE', `/api/v1/vendors/${vendorId}/contacts/${c.id}`, { org: org() });
    await Promise.all([loadContacts(), loadVendor()]);
  } catch (e) {
    contactError.value = e instanceof Error ? e.message : 'Could not remove the contact.';
  }
}

// ── Roles ────────────────────────────────────────────────────────────────────

const canManage    = computed(() => can('vendors.manage'));
const showRoleForm = ref(false);
const savingRole   = ref(false);
const roleError    = ref('');
const roleForm     = reactive({ role: '' as StakeholderRoleName | '', valid_from: '' });

const activeRoles = computed(() => (vendor.value?.roles ?? []).filter(r => r.in_force));

function roleLabel(role: string): string {
  return STAKEHOLDER_ROLES.find(r => r.value === role)?.label
    ?? role.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

/** Roles not already in force, and legal for this party's class. */
const addableRoles = computed(() => {
  const held = new Set(activeRoles.value.map(r => r.role));
  const individual = vendor.value?.party_class === 'individual';
  return STAKEHOLDER_ROLES.filter(r =>
    !held.has(r.value) && (individual ? !r.companyOnly : !r.individualOnly));
});

async function addRole(): Promise<void> {
  if (!roleForm.role) return;
  savingRole.value = true; roleError.value = '';
  try {
    const res = await api<{ data: Vendor }>('POST', `/api/v1/vendors/${vendorId}/roles`, {
      org: org(),
      body: { role: roleForm.role, valid_from: roleForm.valid_from || null },
    });
    vendor.value = res.data;
    showRoleForm.value = false;
    roleForm.role = ''; roleForm.valid_from = '';
  } catch (e) {
    roleError.value = e instanceof Error ? e.message : 'Could not add the role.';
  } finally {
    savingRole.value = false;
  }
}

async function endRole(role: StakeholderRole): Promise<void> {
  if (!window.confirm(`End the ${role.label} role? The history stays on the record.`)) return;
  roleError.value = '';
  try {
    const res = await api<{ data: Vendor }>('PATCH', `/api/v1/vendors/${vendorId}/roles/${role.id}`, {
      org: org(), body: {},
    });
    vendor.value = res.data;
  } catch (e) {
    roleError.value = e instanceof Error ? e.message : 'Could not end the role.';
  }
}

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
interface TierRequirementRow {
  requirement_type: 'training' | 'assessment' | 'certification';
  training_item_id: number | null;
  required_headcount: number;
}

const tForm = reactive({
  tier_name: '', tier_order: 0,
  scope: 'general' as 'general' | 'product',
  vendor_product_id: null as number | null,
  revenue_target: null as number | null, revenue_currency: 'USD',
  seats_required: null as number | null, seats_activated: null as number | null,
  requirements: '', benefits: '', is_current: false,
  tier_requirements: [] as TierRequirementRow[],
});

/** Seats only mean something beside a revenue commitment. */
const tierHasMoney = computed(() => !!tForm.revenue_target && tForm.revenue_target > 0);

function addTierRequirement(): void {
  tForm.tier_requirements.push({
    requirement_type: 'certification', training_item_id: null, required_headcount: 1,
  });
}

function resetTierForm(): void {
  tForm.tier_name = ''; tForm.tier_order = 0;
  tForm.scope = 'general'; tForm.vendor_product_id = null;
  tForm.revenue_target = null; tForm.revenue_currency = 'USD';
  tForm.seats_required = null; tForm.seats_activated = null;
  tForm.requirements = ''; tForm.benefits = ''; tForm.is_current = false;
  tForm.tier_requirements = [];
}

const tiersOrdered = computed(() => [...tiers.value].sort((a, b) => a.tier_order - b.tier_order));
const currentTier  = computed(() => tiers.value.find(t => t.is_current) ?? null);

// ── Tier compliance ──────────────────────────────────────────────────────────
//
// Counted from the same training records the rest of the system keeps, which
// is the whole reason a tier now names its obligations instead of counting
// them: "three certifications" could never be checked against anything.

interface TierComplianceRow {
  tier: { id: number };
  requirements: { id: number; type: string; label: string; required: number; held: number | null; expiring_soon: number; state: string }[];
  commercial: { seats_required: number; seats_activated: number; state: string } | null;
  status: 'compliant' | 'at_risk' | 'non_compliant' | 'unmeasured';
  gaps: string[];
}

const tierCompliance = ref<TierComplianceRow[]>([]);

function complianceFor(tierId: number): TierComplianceRow | null {
  return tierCompliance.value.find(c => c.tier.id === tierId) ?? null;
}

function complianceLabel(status: string): string {
  return {
    compliant: 'Compliant', at_risk: 'At risk',
    non_compliant: 'Not compliant', unmeasured: 'Not measurable',
  }[status] ?? status;
}

async function loadTierCompliance(): Promise<void> {
  try {
    const res = await api<{ data: TierComplianceRow[] }>(
      'GET', `/api/v1/vendors/${vendorId}/tiers/compliance`, { org: org() });
    tierCompliance.value = res.data;
  } catch { tierCompliance.value = []; }
}

async function loadTiers() {
  tierLoading.value = true;
  try {
    const res = await api<{ data: VendorPartnershipTier[] }>('GET', `/api/v1/vendors/${vendorId}/tiers`, { org: org() });
    tiers.value = res.data;
    void loadTierCompliance();
  } finally { tierLoading.value = false; }
}

function openAddTier() {
  editingTier.value = null;
  resetTierForm();
  tFormError.value = null;
  // Products to scope a tier to, and the training catalogue to require from.
  void loadProducts();
  void loadTrainingItems();
  showTierForm.value = true;
}

function openEditTier(t: VendorPartnershipTier) {
  editingTier.value = t;
  resetTierForm();
  tForm.tier_name = t.tier_name; tForm.tier_order = t.tier_order;
  tForm.scope = (t.scope as 'general' | 'product') ?? 'general';
  tForm.vendor_product_id = t.vendor_product_id ?? null;
  tForm.revenue_target = t.revenue_target; tForm.revenue_currency = t.revenue_currency;
  tForm.seats_required = t.seats_required ?? null;
  tForm.seats_activated = t.seats_activated ?? null;
  tForm.requirements = t.requirements ?? ''; tForm.benefits = t.benefits ?? '';
  tForm.is_current = t.is_current;
  tForm.tier_requirements = (t.tier_requirements ?? []).map(r => ({
    requirement_type: r.requirement_type,
    training_item_id: r.training_item_id,
    required_headcount: r.required_headcount,
  }));
  tFormError.value = null;
  void loadProducts();
  void loadTrainingItems();
  showTierForm.value = true;
}

async function saveTier() {
  if (!tForm.tier_name.trim()) { tFormError.value = 'Tier name is required.'; return; }
  saving.value = true; tFormError.value = null;
  if (tForm.scope === 'product' && !tForm.vendor_product_id) {
    tFormError.value = 'Say which product this tier is held on.'; saving.value = false; return;
  }
  if (tForm.tier_requirements.some(r => !r.training_item_id)) {
    tFormError.value = 'Every requirement has to name what is required.'; saving.value = false; return;
  }

  const body = {
    tier_name: tForm.tier_name.trim(), tier_order: tForm.tier_order,
    scope: tForm.scope,
    vendor_product_id: tForm.scope === 'product' ? tForm.vendor_product_id : null,
    revenue_target: tForm.revenue_target, revenue_currency: tForm.revenue_currency.trim() || 'USD',
    // Seats without money are meaningless, so they are not sent.
    seats_required: tierHasMoney.value ? tForm.seats_required : null,
    seats_activated: tierHasMoney.value ? tForm.seats_activated : null,
    requirements: tForm.requirements.trim() || null, benefits: tForm.benefits.trim() || null,
    is_current: tForm.is_current,
    tier_requirements: tForm.tier_requirements,
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
  /* Flex so a title can carry a badge or an action beside it; a title that is
     only text renders exactly as it did before. */
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-semibold);
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border);
  padding-bottom: var(--space-2);
  margin-bottom: var(--space-4);
}

/* ── Supplier scorecard ──────────────────────────────────────────────────────── */
.sc-rating {
  margin-left: auto; text-transform: none; letter-spacing: 0;
  font-size: var(--font-size-xs); padding: 2px 10px; border-radius: 999px;
  background: var(--color-surface-sunken); color: var(--color-text);
}
.sc-rating.ok   { background: color-mix(in oklab, var(--color-success-text) 15%, transparent); color: var(--color-success-text); }
.sc-rating.warn { background: color-mix(in oklab, var(--color-warning-text) 15%, transparent); color: var(--color-warning-text); }
.sc-rating.bad  { background: color-mix(in oklab, var(--color-danger-text) 15%, transparent);  color: var(--color-danger-text); }

.sc-metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: var(--space-3); }
.sc-metric {
  display: flex; flex-direction: column; gap: 2px;
  padding: var(--space-3); border: 1px solid var(--color-border); border-radius: 10px;
}
.sc-n { font-size: var(--font-size-xl); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums; }
.sc-l { font-size: var(--font-size-xs); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.sc-s { font-size: var(--font-size-xs); color: var(--color-text-muted); }

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
