<template>
  <div class="tr-page">
    <header class="tr-header">
      <div>
        <h2>Training</h2>
        <p class="tr-lead">Learning overview and analytics</p>
      </div>
      <div class="tr-header-actions">
        <RouterLink :to="{ name: 'training-report' }" class="btn btn-secondary">Training Report</RouterLink>
        <RouterLink :to="{ name: 'my-training' }" class="btn btn-secondary">My Learning</RouterLink>
      </div>
    </header>

    <p v-if="statsError" class="tr-error">{{ statsError }}</p>

    <section class="tr-stats" aria-label="Training overview">
      <div class="tr-stat-card">
        <span class="tr-stat-label">Active Learners</span>
        <span class="tr-stat-value">{{ stats?.active_learners ?? "—" }}</span>
      </div>
      <div class="tr-stat-card">
        <span class="tr-stat-label">Completions This Month</span>
        <span class="tr-stat-value">{{ stats?.completions_this_month ?? "—" }}</span>
      </div>
      <div class="tr-stat-card">
        <span class="tr-stat-label">Overdue Trainings</span>
        <span class="tr-stat-value" :class="{ 'tr-stat-warn': (stats?.overdue ?? 0) > 0 }">{{ stats?.overdue ?? "—" }}</span>
      </div>
      <div class="tr-stat-card">
        <span class="tr-stat-label">Catalog Items</span>
        <span class="tr-stat-value">{{ stats?.catalog_total ?? "—" }}</span>
      </div>
      <div class="tr-stat-card tr-stat-ring">
        <span class="tr-stat-label">Completion Rate</span>
        <div class="tr-ring" :style="{ '--pct': (stats?.completion_rate_percent ?? 0) + '%' }">
          <span>{{ stats?.completion_rate_percent ?? 0 }}%</span>
        </div>
      </div>
    </section>

    <nav class="tr-tabs" role="tablist" aria-label="Training sections">
      <button type="button" role="tab" :aria-selected="panel === 'catalog'"
              class="tr-tab" :class="{ active: panel === 'catalog' }" @click="selectPanel('catalog')">
        Training Catalog
      </button>
      <button type="button" role="tab" :aria-selected="panel === 'competencies'"
              class="tr-tab" :class="{ active: panel === 'competencies' }" @click="selectPanel('competencies')">
        Competencies
      </button>
      <button type="button" role="tab" :aria-selected="panel === 'budget'"
              class="tr-tab" :class="{ active: panel === 'budget' }" @click="selectPanel('budget')">
        Training Budget
      </button>
    </nav>

    <div class="tr-toolbar">
      <div class="tr-toolbar-left">
        <input v-if="panel !== 'budget'" v-model="search" type="search" class="tr-search"
               :placeholder="panel === 'catalog' ? 'Search the catalog…' : 'Search competencies…'"
               @input="onSearchInput" />
        <button v-if="panel === 'catalog'" type="button" class="btn btn-secondary" @click="filtersOpen = !filtersOpen">
          Filter
        </button>
      </div>
      <div class="tr-toolbar-right">
        <template v-if="panel === 'catalog'">
          <button type="button" class="btn btn-secondary" @click="openAssign()">Assign a Training</button>
          <button type="button" class="btn btn-primary" @click="openAddItem">+ Add Training Item</button>
        </template>
        <button v-else-if="panel === 'competencies'" type="button" class="btn btn-primary" @click="openAddCompetence">+ Add Competence</button>
        <button v-else type="button" class="btn btn-primary" @click="openBudgetDrawer">+ New Budget Request</button>
      </div>
    </div>

    <div v-if="panel === 'catalog' && filtersOpen" class="tr-filters">
      <select v-model="typeFilter" @change="loadCatalog(1)">
        <option v-for="o in TYPE_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <select v-model="deliveryFilter" @change="loadCatalog(1)">
        <option v-for="o in DELIVERY_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
    </div>

    <!-- ── Training Catalog panel ─────────────────────────────────────── -->
    <section v-if="panel === 'catalog'">
      <p v-if="catalogError" class="tr-error">{{ catalogError }}</p>
      <p v-else-if="catalogLoading" class="tr-loading">Loading the catalog…</p>
      <p v-else-if="catalogItems.length === 0" class="tr-empty">
        No training items match yet. Add the first one with "Add Training Item".
      </p>

      <div v-else class="tr-catalog-grid">
        <article v-for="item in catalogItems" :key="item.id" class="catalog-card" @click="goToItem(item)">
          <div class="catalog-card-badges">
            <span v-if="item.is_featured" class="badge badge-amber">Featured</span>
            <span class="badge" :class="typeBadge(item.type)">{{ item.type.replace('_', ' ') }}</span>
            <span class="badge badge-gray">{{ item.delivery.replace('_', ' ') }}</span>
          </div>
          <span v-if="item.training_code" class="catalog-card-code">{{ item.training_code }}</span>
          <h3 class="catalog-card-title">{{ item.title }}</h3>
          <p class="catalog-card-desc">{{ item.description || "No description yet." }}</p>
          <div class="catalog-card-footer">
            <span>{{ item.duration_hours }}h {{ item.duration_minutes }}m</span>
            <span>{{ item.training_records_count ?? 0 }} enrolled</span>
            <span>{{ item.vendor_partner?.name || item.certifying_body?.name || item.provider_name || "In-house" }}</span>
          </div>
          <div class="catalog-card-actions">
            <button type="button" class="link-btn" @click="openEditItem(item, $event)">Edit</button>
            <button type="button" class="link-btn link-btn-danger" @click="deleteItem(item, $event)">Delete</button>
          </div>
        </article>
      </div>

      <div v-if="catalogLastPage > 1" class="tr-pagination">
        <button type="button" class="btn btn-secondary" :disabled="!catalogHasPrev" @click="loadCatalog(catalogPage - 1)">Previous</button>
        <span>Page {{ catalogPage }} of {{ catalogLastPage }} · {{ catalogTotal }} items</span>
        <button type="button" class="btn btn-secondary" :disabled="!catalogHasNext" @click="loadCatalog(catalogPage + 1)">Next</button>
      </div>
    </section>

    <!-- ── Competencies panel ─────────────────────────────────────────── -->
    <section v-else-if="panel === 'competencies'">
      <p v-if="competenciesError" class="tr-error">{{ competenciesError }}</p>
      <p v-else-if="competenciesLoading" class="tr-loading">Loading competencies…</p>
      <p v-else-if="filteredCompetencies.length === 0" class="tr-empty">
        No competencies recorded yet. Add the first one with "Add Competence".
      </p>

      <div v-else class="tr-competency-grid">
        <article v-for="c in filteredCompetencies" :key="c.id" class="competency-card">
          <div class="catalog-card-badges">
            <span v-if="c.category" class="badge badge-indigo">{{ c.category }}</span>
          </div>
          <h3 class="catalog-card-title">{{ c.name }}</h3>
          <p class="catalog-card-desc">{{ c.description || "No description yet." }}</p>
          <div class="catalog-card-footer">
            <span>{{ c.proficiency_levels?.length ?? 0 }} proficiency level{{ (c.proficiency_levels?.length ?? 0) === 1 ? "" : "s" }}</span>
          </div>
        </article>
      </div>
    </section>

    <!-- ── Training Budget panel ────────────────────────────────────────── -->
    <section v-else>
      <p v-if="budgetError" class="tr-error">{{ budgetError }}</p>
      <p v-else-if="budgetLoading" class="tr-loading">Loading the budget…</p>
      <p v-else-if="budgetSummary.length === 0" class="tr-empty">
        No active catalog items yet — the budget has nothing to summarise.
      </p>

      <template v-else>
        <div v-for="row in budgetSummary" :key="row.cost_currency" class="tr-budget-currency-block">
          <p class="tr-field-group-label">{{ row.cost_currency }}</p>
          <div class="tr-stats tr-budget-stats">
            <div class="tr-stat-card">
              <span class="tr-stat-label">Budgeted Items</span>
              <span class="tr-stat-value">{{ row.budgeted_items }} / {{ row.total_items }}</span>
            </div>
            <div class="tr-stat-card">
              <span class="tr-stat-label">Total Budgeted</span>
              <span class="tr-stat-value">{{ row.cost_currency }} {{ Number(row.total_budgeted_amount).toLocaleString() }}</span>
            </div>
            <div class="tr-stat-card">
              <span class="tr-stat-label">Total Estimated Cost</span>
              <span class="tr-stat-value">{{ row.cost_currency }} {{ Number(row.total_estimated_cost).toLocaleString() }}</span>
            </div>
            <div class="tr-stat-card">
              <span class="tr-stat-label">Budgeted − Estimated</span>
              <span class="tr-stat-value" :class="{ 'tr-stat-warn': Number(row.total_budgeted_amount) < Number(row.total_estimated_cost) }">
                {{ row.cost_currency }} {{ (Number(row.total_budgeted_amount) - Number(row.total_estimated_cost)).toLocaleString() }}
              </span>
            </div>
          </div>
        </div>

        <p class="tr-field-group-label">Items</p>
        <div class="tr-budget-table-wrap">
          <table class="tr-budget-table">
            <thead>
              <tr><th>Title</th><th>Type</th><th>Budgeted</th><th>Budget Amount</th><th>Estimated Cost</th></tr>
            </thead>
            <tbody>
              <tr v-for="item in budgetedCatalogItems" :key="item.id" @click="goToItem(item)">
                <td>{{ item.title }}</td>
                <td><span class="badge" :class="typeBadge(item.type)">{{ item.type.replace('_', ' ') }}</span></td>
                <td>
                  <span class="badge" :class="item.is_budgeted ? 'badge-green' : 'badge-gray'">
                    {{ item.is_budgeted ? "Yes" : "No" }}
                  </span>
                </td>
                <td>{{ item.is_budgeted ? `${item.cost_currency} ${Number(item.budget_amount ?? 0).toLocaleString()}` : "—" }}</td>
                <td>{{ item.cost_currency }} {{ Number(item.estimated_cost).toLocaleString() }}</td>
              </tr>
              <tr v-if="budgetedCatalogItems.length === 0">
                <td colspan="5" class="tr-empty">No catalog items on this page — visit the Training Catalog tab.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <p class="tr-field-group-label">Budget Requests</p>
      <p v-if="budgetRequestsError" class="tr-error">{{ budgetRequestsError }}</p>
      <p v-else-if="budgetRequestsLoading" class="tr-loading">Loading budget requests…</p>
      <p v-else-if="budgetRequests.length === 0" class="tr-empty">
        No budget requests yet. Start one with "New Budget Request".
      </p>
      <div v-else class="tr-budget-table-wrap">
        <table class="tr-budget-table">
          <thead>
            <tr><th>Request</th><th>Total</th><th>Status</th><th>Submitted</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="req in budgetRequests" :key="req.id">
              <td>
                <template v-if="req.mode === 'by_training'">
                  {{ req.training_item?.title ?? "—" }}
                  <div class="tr-assign-email">{{ req.items?.length ?? 0 }} employee{{ (req.items?.length ?? 0) === 1 ? "" : "s" }}</div>
                </template>
                <template v-else>
                  {{ req.employee?.name ?? "—" }}
                  <div class="tr-assign-email">{{ req.items?.length ?? 0 }} training{{ (req.items?.length ?? 0) === 1 ? "" : "s" }}</div>
                </template>
              </td>
              <td>{{ req.currency }} {{ Number(req.total_amount).toLocaleString() }}</td>
              <td><span class="badge" :class="budgetStatusBadge(req.status)">{{ budgetStatusLabel(req.status) }}</span></td>
              <td>{{ new Date(req.created_at).toLocaleDateString() }}</td>
              <td>
                <div v-if="req.status === 'pending_manager' || req.status === 'pending_finance'" class="catalog-card-actions">
                  <button type="button" class="link-btn" @click="approveBudgetRequest(req)">Approve</button>
                  <button type="button" class="link-btn link-btn-danger" @click="rejectBudgetRequest(req)">Reject</button>
                </div>
                <span v-else-if="req.status === 'rejected'" class="tr-assign-email">{{ req.rejection_reason }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ── Add / Edit Training Item drawer ────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showItemDrawer" class="tr-drawer-backdrop" @click.self="showItemDrawer = false">
        <aside class="tr-drawer">
          <header class="tr-drawer-header">
            <h3>{{ editingItemId ? "Edit Training Item" : "New Training Item" }}</h3>
            <button type="button" class="tr-drawer-close" @click="showItemDrawer = false">×</button>
          </header>
          <div class="tr-drawer-body">
            <p v-if="itemError" class="tr-error">{{ itemError }}</p>

            <p class="tr-field-group-label">Basic Information</p>
            <div v-if="editingTrainingCode" class="tr-field-row">
              <label>Training Code
                <input :value="editingTrainingCode" type="text" disabled />
              </label>
              <label>Version<input v-model="itemForm.training_version" type="text" placeholder="e.g. v2, 2026 edition" /></label>
            </div>
            <label v-else>Version (optional)<input v-model="itemForm.training_version" type="text" placeholder="e.g. v2, 2026 edition" /></label>
            <p v-if="!editingTrainingCode" class="tr-field-hint">A training code is assigned automatically once saved.</p>
            <label>Title *<input v-model="itemForm.title" type="text" /></label>
            <label>Description *<textarea v-model="itemForm.description" rows="3"></textarea></label>

            <p class="tr-field-group-label">Classification</p>
            <div class="tr-field-row">
              <label>Type
                <select v-model="itemForm.type">
                  <option v-for="o in TYPE_OPTIONS.filter(t => t.value)" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
              </label>
              <label>Delivery Mode
                <select v-model="itemForm.delivery">
                  <option v-for="o in DELIVERY_OPTIONS.filter(d => d.value)" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
              </label>
            </div>
            <label>Training Level
              <select v-model="itemForm.level">
                <option value="">— Not set —</option>
                <option v-for="o in LEVEL_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>
            <label class="tr-checkbox"><input v-model="itemForm.is_technical" type="checkbox" /> Technical training</label>
            <label class="tr-checkbox"><input v-model="itemForm.requires_proctored_exam" type="checkbox" /> Proctored exam required</label>

            <p class="tr-field-group-label">Skills Earned</p>
            <label>Skills / Competencies
              <select v-model="competencyToAdd" @change="addCompetency" :disabled="availableCompetencies.length === 0">
                <option value="" disabled>{{ availableCompetencies.length ? "Add a skill…" : "No more skills to add" }}</option>
                <option v-for="c in availableCompetencies" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </label>
            <p v-if="competencies.length === 0" class="tr-field-hint">
              No competencies exist yet — add one from the Competencies tab first.
            </p>
            <div v-if="itemForm.competency_ids.length" class="tr-chip-list">
              <span v-for="id in itemForm.competency_ids" :key="id" class="tr-chip">
                {{ competencyName(id) }}
                <button type="button" @click="removeCompetency(id)">×</button>
              </span>
            </div>

            <p class="tr-field-group-label">Training Provider</p>
            <label>Training Provider Type *
              <select v-model="itemForm.training_provider_type" @change="onProviderTypeChange">
                <option value="" disabled>— Select —</option>
                <option v-for="o in PROVIDER_TYPE_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>

            <label v-if="itemForm.training_provider_type === 'certifying_body'">Certifying Professional Body *
              <select v-model="itemForm.certifying_body_id">
                <option :value="null" disabled>Choose a professional body…</option>
                <option v-for="b in certifyingBodies" :key="b.id" :value="b.id">{{ b.name }}</option>
              </select>
            </label>

            <template v-if="itemForm.training_provider_type === 'vendor_oem'">
              <label>Vendor *
                <select v-model="itemForm.vendor_partner_id" @change="onVendorPartnerChange">
                  <option :value="null" disabled>Choose a vendor…</option>
                  <option v-for="v in oemVendors" :key="v.id" :value="v.id">{{ v.name }}</option>
                </select>
              </label>
              <p v-if="oemVendors.length === 0" class="tr-field-hint">
                No vendors are flagged as OEM/product vendors yet — configure one in Vendor Management.
              </p>

              <label v-if="itemForm.vendor_partner_id">Products Covered *
                <select v-model="productToAdd" @change="addProduct" :disabled="vendorProductsLoading || availableProducts.length === 0">
                  <option value="" disabled>
                    {{ vendorProductsLoading ? "Loading products…" : (availableProducts.length ? "Add a product…" : "No more products to add") }}
                  </option>
                  <option v-for="p in availableProducts" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <div v-if="itemForm.product_ids.length" class="tr-chip-list">
                  <span v-for="id in itemForm.product_ids" :key="id" class="tr-chip">
                    {{ productName(id) }}
                    <button type="button" @click="removeProduct(id)">×</button>
                  </span>
                </div>
              </label>

              <label v-if="itemForm.vendor_partner_id" class="tr-checkbox">
                <input v-model="itemForm.oem_compliance_required" type="checkbox" />
                Required for {{ oemVendorName(itemForm.vendor_partner_id) }}'s partnership compliance
              </label>
              <div v-if="itemForm.oem_compliance_required" class="tr-field-row">
                <label>Required Headcount<input v-model.number="itemForm.oem_compliance_headcount" type="number" min="1" placeholder="1" /></label>
              </div>
              <label v-if="itemForm.oem_compliance_required">Compliance Notes
                <textarea v-model="itemForm.oem_compliance_notes" rows="2" placeholder="e.g. Counts toward Gold Partner tier requirement"></textarea>
              </label>
            </template>

            <p class="tr-field-group-label">Duration &amp; Capacity</p>
            <div class="tr-field-row">
              <label>Hours<input v-model.number="itemForm.duration_hours" type="number" min="0" /></label>
              <label>Minutes<input v-model.number="itemForm.duration_minutes" type="number" min="0" max="59" /></label>
              <label>Max Enrollment<input v-model.number="itemForm.max_enrollments" type="number" min="0" /></label>
            </div>
            <p class="tr-field-group-label">Provider &amp; Cost</p>
            <div class="tr-field-row">
              <label>Provider<input v-model="itemForm.provider_name" type="text" /></label>
              <label>Estimated Cost<input v-model.number="itemForm.estimated_cost" type="number" min="0" step="0.01" /></label>
              <label>Currency<input v-model="itemForm.cost_currency" type="text" maxlength="3" placeholder="KES" style="text-transform:uppercase;" /></label>
            </div>
            <label class="tr-checkbox"><input v-model="itemForm.is_budgeted" type="checkbox" /> Budgeted</label>
            <label v-if="itemForm.is_budgeted">Budget Amount
              <input v-model.number="itemForm.budget_amount" type="number" min="0" step="0.01" />
            </label>

            <label class="tr-checkbox"><input v-model="itemForm.certificate_issued" type="checkbox" /> Certificate issued on completion</label>
            <template v-if="itemForm.certificate_issued">
              <p class="tr-field-group-label">Certification Details</p>
              <label>Certification Name<input v-model="itemForm.certification_name" type="text" placeholder="e.g. AWS Certified Solutions Architect" /></label>
              <div class="tr-field-row">
                <label>Exam Code<input v-model="itemForm.exam_code" type="text" placeholder="e.g. SAA-C03" /></label>
                <label>Exam Duration<input v-model="itemForm.exam_duration" type="text" placeholder="e.g. 130 minutes" /></label>
                <label>Passing Score<input v-model.number="itemForm.passing_score" type="number" min="0" max="100" /></label>
              </div>
              <div class="tr-field-row">
                <label>Exam Provider
                  <select v-if="!examProviderIsCustom" v-model="itemForm.exam_provider" @change="onExamProviderSelectChange">
                    <option value="">— None —</option>
                    <option v-for="p in EXAM_PROVIDER_OPTIONS" :key="p" :value="p">{{ p }}</option>
                    <option value="__custom__">Custom Provider…</option>
                  </select>
                  <div v-else class="tr-inline-field">
                    <input v-model="itemForm.exam_provider" type="text" placeholder="Exam provider" />
                    <button type="button" class="link-btn" @click="examProviderIsCustom = false; itemForm.exam_provider = ''">Choose from list</button>
                  </div>
                </label>
                <label>Validity (years)<input v-model.number="itemForm.validity_years" type="number" min="0" /></label>
                <label>CPE Credits<input v-model.number="itemForm.cpe_credits" type="number" min="0" /></label>
              </div>
              <label class="tr-checkbox"><input v-model="itemForm.renewal_required" type="checkbox" /> Renewal required</label>
            </template>

            <label class="tr-checkbox"><input v-model="itemForm.is_featured" type="checkbox" /> Featured</label>
            <label class="tr-checkbox"><input v-model="itemForm.requires_approval" type="checkbox" /> Requires approval</label>
            <label class="tr-checkbox"><input v-model="itemForm.is_active" type="checkbox" /> Active</label>
          </div>
          <footer class="tr-drawer-footer">
            <button type="button" class="btn btn-secondary" @click="showItemDrawer = false">Cancel</button>
            <button type="button" class="btn btn-primary" :disabled="itemSaving" @click="saveItem">
              {{ itemSaving ? "Saving…" : "Save" }}
            </button>
          </footer>
        </aside>
      </div>
    </Teleport>

    <!-- ── Add Competence drawer ──────────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showCompetenceDrawer" class="tr-drawer-backdrop" @click.self="showCompetenceDrawer = false">
        <aside class="tr-drawer">
          <header class="tr-drawer-header">
            <h3>Add Competence</h3>
            <button type="button" class="tr-drawer-close" @click="showCompetenceDrawer = false">×</button>
          </header>
          <div class="tr-drawer-body">
            <p v-if="competenceError" class="tr-error">{{ competenceError }}</p>
            <label>Name *<input v-model="competenceForm.name" type="text" /></label>
            <label>Description<textarea v-model="competenceForm.description" rows="3"></textarea></label>
            <label>Category<input v-model="competenceForm.category" type="text" placeholder="e.g. Technical, Leadership" /></label>
          </div>
          <footer class="tr-drawer-footer">
            <button type="button" class="btn btn-secondary" @click="showCompetenceDrawer = false">Cancel</button>
            <button type="button" class="btn btn-primary" :disabled="competenceSaving" @click="saveCompetence">
              {{ competenceSaving ? "Saving…" : "Save" }}
            </button>
          </footer>
        </aside>
      </div>
    </Teleport>

    <!-- ── Assign a Training drawer ───────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showAssignDrawer" class="tr-drawer-backdrop" @click.self="showAssignDrawer = false">
        <aside class="tr-drawer tr-drawer-wide">
          <header class="tr-drawer-header">
            <h3>Assign Training</h3>
            <button type="button" class="tr-drawer-close" @click="showAssignDrawer = false">×</button>
          </header>
          <div class="tr-drawer-body">
            <p v-if="assignError" class="tr-error">{{ assignError }}</p>
            <label>Training *
              <select v-model="assignTrainingId">
                <option value="" disabled>Choose a training item…</option>
                <option v-for="item in catalogItems" :key="item.id" :value="item.id">{{ item.title }}</option>
              </select>
            </label>

            <div class="tr-assign-emp-header">
              <input v-model="assignSearch" type="search" class="tr-search" placeholder="Search employees…" />
              <button type="button" class="btn btn-secondary" @click="toggleAssignAll">
                {{ assignAllSelected ? "Clear All" : "Select All" }}
              </button>
              <span class="tr-assign-count">({{ assignSelected.size }} selected)</span>
            </div>

            <div class="tr-assign-table-wrap">
              <table class="tr-assign-table">
                <thead>
                  <tr><th></th><th>Name</th><th>Department</th></tr>
                </thead>
                <tbody>
                  <tr v-for="e in assignFilteredEmployees" :key="e.id" @click="toggleAssignOne(e.id)">
                    <td><input type="checkbox" :checked="assignSelected.has(e.id)" @click.stop="toggleAssignOne(e.id)" /></td>
                    <td>
                      <div class="tr-assign-name">{{ e.full_name }}</div>
                      <div class="tr-assign-email">{{ e.work_email || e.email || "" }}</div>
                    </td>
                    <td>{{ e.department?.name || "—" }}</td>
                  </tr>
                  <tr v-if="assignFilteredEmployees.length === 0">
                    <td colspan="3" class="tr-empty">No employees match.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <footer class="tr-drawer-footer">
            <button type="button" class="btn btn-secondary" @click="showAssignDrawer = false">Cancel</button>
            <button type="button" class="btn btn-primary" :disabled="assignSaving" @click="submitAssign">
              {{ assignSaving ? "Assigning…" : "Assign" }}
            </button>
          </footer>
        </aside>
      </div>
    </Teleport>

    <!-- ── New Budget Request drawer ──────────────────────────────────── -->
    <Teleport to="body">
      <div v-if="showBudgetDrawer" class="tr-drawer-backdrop" @click.self="showBudgetDrawer = false">
        <aside class="tr-drawer tr-drawer-wide">
          <header class="tr-drawer-header">
            <h3>New Budget Request</h3>
            <button type="button" class="tr-drawer-close" @click="showBudgetDrawer = false">×</button>
          </header>
          <div class="tr-drawer-body">
            <p v-if="budgetFormError" class="tr-error">{{ budgetFormError }}</p>

            <label>What is this budget for? *
              <select v-model="budgetForm.mode">
                <option value="by_training">One training, several employees</option>
                <option value="by_employee">One employee, several trainings</option>
              </select>
            </label>

            <!-- ── Mode: one training, several employees ────────────────── -->
            <template v-if="budgetForm.mode === 'by_training'">
              <label>Training *
                <select v-model="budgetForm.training_item_id" @change="onBudgetTrainingChange">
                  <option :value="null" disabled>Choose a training item…</option>
                  <option v-for="item in catalogItems" :key="item.id" :value="item.id">{{ item.title }}</option>
                </select>
              </label>

              <div class="tr-assign-emp-header">
                <input v-model="budgetEmployeeSearch" type="search" class="tr-search" placeholder="Search employees…" />
                <span class="tr-assign-count">({{ Object.keys(budgetLineByEmployee).length }} selected)</span>
              </div>

              <div class="tr-assign-table-wrap">
                <table class="tr-assign-table">
                  <thead>
                    <tr><th></th><th>Name</th><th>Cost</th><th>Achieve By</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="e in budgetFilteredEmployees" :key="e.id">
                      <td><input type="checkbox" :checked="!!budgetLineByEmployee[e.id]" @change="toggleBudgetEmployee(e)" /></td>
                      <td>
                        <div class="tr-assign-name">{{ e.full_name }}</div>
                      </td>
                      <td>
                        <input v-if="budgetLineByEmployee[e.id]" v-model.number="budgetLineByEmployee[e.id].cost" type="number" min="0" step="0.01" style="width:100px;" />
                      </td>
                      <td>
                        <input v-if="budgetLineByEmployee[e.id]" v-model="budgetLineByEmployee[e.id].achieve_by_date" type="date" />
                      </td>
                    </tr>
                    <tr v-if="budgetFilteredEmployees.length === 0">
                      <td colspan="4" class="tr-empty">No employees match.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </template>

            <!-- ── Mode: one employee, several trainings ─────────────────── -->
            <template v-else>
              <label>Employee *
                <select v-model="budgetForm.employee_id">
                  <option :value="null" disabled>Choose an employee…</option>
                  <option v-for="e in assignEmployees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                </select>
              </label>

              <p class="tr-field-group-label">Trainings / Certifications</p>
              <div v-for="row in budgetTrainingRows" :key="row.key" class="tr-field-row" style="align-items:flex-end;">
                <label>Training
                  <select v-model="row.training_item_id" @change="onBudgetRowTrainingChange(row)">
                    <option :value="''" disabled>Choose…</option>
                    <option v-for="item in catalogItems" :key="item.id" :value="item.id">{{ item.title }}</option>
                  </select>
                </label>
                <label>Cost<input v-model.number="row.cost" type="number" min="0" step="0.01" /></label>
                <label>Achieve By (realisation date)<input v-model="row.achieve_by_date" type="date" /></label>
                <button type="button" class="link-btn link-btn-danger" @click="removeBudgetTrainingRow(row.key)">Remove</button>
              </div>
              <button type="button" class="btn btn-secondary" @click="addBudgetTrainingRow">+ Add Another Training</button>
            </template>

            <p class="tr-field-group-label">Payment</p>
            <label>Payment Instructions *
              <textarea v-model="budgetForm.payment_instructions" rows="3" placeholder="e.g. Pay the provider directly via invoice upon confirmation of enrolment."></textarea>
            </label>
            <label>Currency<input v-model="budgetForm.currency" type="text" maxlength="3" style="text-transform:uppercase;width:100px;" /></label>

            <div class="tr-budget-total-box">
              <span>Total</span>
              <strong>{{ budgetForm.currency }} {{ budgetTotal.toLocaleString() }}</strong>
              <span class="tr-field-hint" style="margin:0;">
                {{ budgetNeedsFinance ? "Requires your manager's approval, then Finance." : "Requires your manager's approval." }}
              </span>
            </div>
          </div>
          <footer class="tr-drawer-footer">
            <button type="button" class="btn btn-secondary" @click="showBudgetDrawer = false">Cancel</button>
            <button type="button" class="btn btn-primary" :disabled="budgetSaving" @click="submitBudgetRequest">
              {{ budgetSaving ? "Submitting…" : "Submit for Approval" }}
            </button>
          </footer>
        </aside>
      </div>
    </Teleport>
  </div>
</template>
<script setup lang="ts">
/**
 * Training & Competency, rebuilt against the HR portal design.
 *
 * The page used to dead-end: five KPI cards and four buttons that all
 * navigated away, with nothing rendered below them — the "Catalog" nav item
 * landed here and showed an empty page under the stats. The design keeps
 * everything in one place: an overview strip, then the catalog and
 * competencies as two tabs of the *same* page rather than two separate
 * screens reached by leaving this one.
 *
 * The old TrainingCatalogView.vue and CompetencyFrameworkView.vue routes
 * still exist as redirects — see router/index.ts — since their URLs may be
 * bookmarked; the working screens are here now.
 */
import { ref, reactive, computed, onMounted, watch } from "vue";
import { useRouter, useRoute } from "vue-router";
import { api, ApiError } from "../../lib/api";
import { currentOrgSlug } from "../../stores/auth";
import type {
  TrainingDashboardStats, TrainingItem, CompetencyItem, Paginator, Employee,
  TrainingProviderType, TrainingCertifyingBody, Vendor, TrainingBudgetSummaryRow,
  TrainingBudgetRequestSummary, TrainingBudgetThreshold,
} from "../../lib/types";

const router = useRouter();
const route  = useRoute();

function org(): string | null { return currentOrgSlug.value; }

// ── The tabs of this page ───────────────────────────────────────────────
type PanelTab = "catalog" | "competencies" | "budget";
const panel = ref<PanelTab>(
  route.query.tab === "competencies" ? "competencies"
  : route.query.tab === "budget" ? "budget"
  : "catalog"
);

function selectPanel(next: PanelTab): void {
  panel.value = next;
  filtersOpen.value = false;
}

// ── Overview stats ─────────────────────────────────────────────────────────
const stats   = ref<TrainingDashboardStats | null>(null);
const statsError = ref("");

async function loadStats(): Promise<void> {
  if (!org()) return;
  statsError.value = "";
  try {
    stats.value = await api<TrainingDashboardStats>("GET", "/api/v1/training/dashboard", { org: org() });
  } catch (e) {
    statsError.value = e instanceof Error ? e.message : "Could not load the overview.";
  }
}

// ── Shared toolbar ─────────────────────────────────────────────────────────
const search = ref("");
const filtersOpen = ref(false);
let searchDebounce: ReturnType<typeof setTimeout>;

function onSearchInput(): void {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    panel.value === "catalog" ? loadCatalog(1) : loadCompetencies();
  }, 350);
}

// ── Training Catalog ────────────────────────────────────────────────────────
const catalogItems = ref<TrainingItem[]>([]);
const catalogTotal  = ref(0);
const catalogPage   = ref(1);
const catalogLastPage = ref(1);
const catalogLoading  = ref(false);
const catalogError    = ref("");
const typeFilter      = ref("");
const deliveryFilter  = ref("");
const PER_PAGE = 20;

const catalogHasPrev = computed(() => catalogPage.value > 1);
const catalogHasNext = computed(() => catalogPage.value < catalogLastPage.value);

const TYPE_OPTIONS = [
  { label: "All Types", value: "" },
  { label: "Course", value: "course" },
  { label: "Workshop", value: "workshop" },
  { label: "Certification", value: "certification" },
  { label: "Exam Voucher", value: "exam_voucher" },
  { label: "Subscription", value: "subscription" },
  { label: "On-the-Job", value: "on_job" },
  { label: "Mentoring", value: "mentoring" },
  { label: "Conference", value: "conference" },
];
const DELIVERY_OPTIONS = [
  { label: "All Delivery", value: "" },
  { label: "E-Learning", value: "e_learning" },
  { label: "In-Person", value: "in_person" },
  { label: "Virtual", value: "virtual" },
  { label: "Blended", value: "blended" },
  { label: "Self-Study", value: "self_study" },
];
const LEVEL_OPTIONS = [
  { label: "Beginner", value: "beginner" },
  { label: "Foundation", value: "foundation" },
  { label: "Associate", value: "associate" },
  { label: "Professional", value: "professional" },
  { label: "Expert", value: "expert" },
  { label: "Master", value: "master" },
];
const EXAM_PROVIDER_OPTIONS = ["Pearson VUE", "PSI", "Kryterion", "Certiport", "ISC2", "Prometric"];
const PROVIDER_TYPE_OPTIONS: { label: string; value: TrainingProviderType }[] = [
  { label: "Certifying Professional Body", value: "certifying_body" },
  { label: "Vendor (OEM Based)", value: "vendor_oem" },
];

async function loadCatalog(page = 1): Promise<void> {
  if (!org()) { catalogError.value = "Select an organisation first."; return; }
  catalogLoading.value = true; catalogError.value = "";
  catalogPage.value = page;
  try {
    const res = await api<Paginator<TrainingItem>>("GET", "/api/v1/training/catalog", {
      org: org(),
      params: {
        page, per_page: PER_PAGE,
        type: typeFilter.value || undefined,
        delivery: deliveryFilter.value || undefined,
        search: search.value || undefined,
      },
    });
    catalogItems.value = res.data;
    catalogTotal.value = res.meta.total;
    catalogLastPage.value = res.meta.last_page;
  } catch (e) {
    catalogError.value = e instanceof Error ? e.message : "Could not load the catalog.";
  } finally { catalogLoading.value = false; }
}

function typeBadge(type: string): string {
  const map: Record<string, string> = {
    course: "badge-blue", workshop: "badge-indigo", certification: "badge-teal",
    exam_voucher: "badge-amber", subscription: "badge-blue", on_job: "badge-green",
    mentoring: "badge-teal", conference: "badge-indigo",
  };
  return map[type] ?? "badge-gray";
}

function goToItem(item: TrainingItem): void {
  router.push({ name: "training-item-detail", params: { id: item.id } });
}

// ── Training Budget ──────────────────────────────────────────────────────
const budgetSummary = ref<TrainingBudgetSummaryRow[]>([]);
const budgetLoading  = ref(false);
const budgetError    = ref("");

async function loadBudgetSummary(): Promise<void> {
  if (!org()) return;
  budgetLoading.value = true; budgetError.value = "";
  try {
    const res = await api<{ data: TrainingBudgetSummaryRow[] }>("GET", "/api/v1/training/catalog/budget-summary", { org: org() });
    budgetSummary.value = res.data;
  } catch (e) {
    budgetError.value = e instanceof Error ? e.message : "Could not load the budget summary.";
  } finally { budgetLoading.value = false; }
}

const budgetedCatalogItems = computed(() =>
  [...catalogItems.value].sort((a, b) => (b.budget_amount ?? 0) - (a.budget_amount ?? 0)));

// ── Training item drawer (add / edit) ──────────────────────────────────────
const showItemDrawer = ref(false);
const editingItemId  = ref<number | null>(null);
const itemSaving     = ref(false);
const itemError      = ref("");

const blankItemForm = () => ({
  title: "", training_version: "", description: "", type: "course", delivery: "e_learning",
  level: "" as string,
  duration_hours: 1, duration_minutes: 0,
  provider_name: "", provider_url: "", cost_currency: "USD", estimated_cost: 0,
  is_active: true, is_featured: false, requires_approval: false,
  is_budgeted: false, budget_amount: null as number | null,
  max_enrollments: null as number | null, tags: [] as string[],
  is_technical: false, requires_proctored_exam: false,
  certificate_issued: false, certification_name: "", exam_code: "",
  exam_duration: "", passing_score: null as number | null, exam_provider: "" as string,
  validity_years: null as number | null, renewal_required: false, cpe_credits: null as number | null,
  training_provider_type: "" as TrainingProviderType | "",
  certifying_body_id: null as number | null,
  vendor_partner_id: null as number | null,
  product_ids: [] as number[],
  oem_compliance_required: false,
  oem_compliance_headcount: null as number | null,
  oem_compliance_notes: "",
  competency_ids: [] as number[],
});
const itemForm = reactive(blankItemForm());
const editingTrainingCode = ref<string | null>(null);
const examProviderIsCustom = ref(false);

function onExamProviderSelectChange(): void {
  if (itemForm.exam_provider === "__custom__") {
    examProviderIsCustom.value = true;
    itemForm.exam_provider = "";
  }
}

// ── Training Provider (certifying body vs. OEM vendor) ─────────────────────
const certifyingBodies = ref<TrainingCertifyingBody[]>([]);
const oemVendors = ref<Vendor[]>([]);
const vendorProducts = ref<{ id: number; name: string }[]>([]);
const vendorProductsLoading = ref(false);
const productToAdd = ref<number | "">("");

const availableProducts = computed(() =>
  vendorProducts.value.filter(p => !itemForm.product_ids.includes(p.id)));

async function loadCertifyingBodies(): Promise<void> {
  try {
    const res = await api<{ data: TrainingCertifyingBody[] }>("GET", "/api/v1/training/certifying-bodies", { org: org() });
    certifyingBodies.value = res.data;
  } catch { certifyingBodies.value = []; }
}

async function loadOemVendors(): Promise<void> {
  try {
    const res = await api<{ data: Vendor[] }>("GET", "/api/v1/vendors", {
      org: org(), params: { is_oem_vendor: true, is_active: true, per_page: 200 },
    });
    oemVendors.value = res.data;
  } catch { oemVendors.value = []; }
}

async function loadVendorProducts(vendorId: number): Promise<void> {
  vendorProductsLoading.value = true;
  try {
    const res = await api<{ data: { id: number; name: string }[] }>(
      "GET", `/api/v1/vendors/${vendorId}/products`, { org: org() });
    vendorProducts.value = res.data;
  } catch { vendorProducts.value = []; }
  finally { vendorProductsLoading.value = false; }
}

function onProviderTypeChange(): void {
  if (itemForm.training_provider_type === "certifying_body") {
    itemForm.vendor_partner_id = null;
    itemForm.product_ids = [];
    vendorProducts.value = [];
    resetOemCompliance();
  } else if (itemForm.training_provider_type === "vendor_oem") {
    itemForm.certifying_body_id = null;
  }
}

function onVendorPartnerChange(): void {
  itemForm.product_ids = [];
  vendorProducts.value = [];
  productToAdd.value = "";
  // A requirement recorded against the old vendor doesn't carry over to a
  // different one — re-check the box for the new vendor if it still applies.
  resetOemCompliance();
  if (itemForm.vendor_partner_id) loadVendorProducts(itemForm.vendor_partner_id);
}

function resetOemCompliance(): void {
  itemForm.oem_compliance_required = false;
  itemForm.oem_compliance_headcount = null;
  itemForm.oem_compliance_notes = "";
}

function oemVendorName(vendorId: number | null): string {
  return oemVendors.value.find(v => v.id === vendorId)?.name ?? "this vendor";
}

function addProduct(): void {
  if (productToAdd.value && !itemForm.product_ids.includes(productToAdd.value)) {
    itemForm.product_ids.push(productToAdd.value);
  }
  productToAdd.value = "";
}

function removeProduct(id: number): void {
  itemForm.product_ids = itemForm.product_ids.filter(pid => pid !== id);
}

function productName(id: number): string {
  return vendorProducts.value.find(p => p.id === id)?.name ?? `#${id}`;
}

// ── Skills earned (reuses the app's existing Competencies, not a new list) ──
const competencyToAdd = ref<number | "">("");

const availableCompetencies = computed(() =>
  competencies.value.filter(c => !itemForm.competency_ids.includes(c.id)));

function addCompetency(): void {
  if (competencyToAdd.value && !itemForm.competency_ids.includes(competencyToAdd.value)) {
    itemForm.competency_ids.push(competencyToAdd.value);
  }
  competencyToAdd.value = "";
}

function removeCompetency(id: number): void {
  itemForm.competency_ids = itemForm.competency_ids.filter(cid => cid !== id);
}

function competencyName(id: number): string {
  return competencies.value.find(c => c.id === id)?.name ?? `#${id}`;
}

function openAddItem(): void {
  Object.assign(itemForm, blankItemForm());
  editingItemId.value = null;
  editingTrainingCode.value = null;
  examProviderIsCustom.value = false;
  vendorProducts.value = [];
  productToAdd.value = "";
  competencyToAdd.value = "";
  itemError.value = "";
  showItemDrawer.value = true;
}

function openEditItem(item: TrainingItem, e?: Event): void {
  e?.stopPropagation();
  Object.assign(itemForm, {
    title: item.title, training_version: item.training_version ?? "", description: item.description ?? "",
    type: item.type, delivery: item.delivery, level: item.level ?? "",
    duration_hours: item.duration_hours, duration_minutes: item.duration_minutes,
    provider_name: item.provider_name ?? "", provider_url: item.provider_url ?? "",
    cost_currency: item.cost_currency, estimated_cost: item.estimated_cost,
    is_active: item.is_active, is_featured: item.is_featured,
    requires_approval: item.requires_approval, is_budgeted: item.is_budgeted,
    budget_amount: item.budget_amount ?? null, max_enrollments: item.max_enrollments ?? null,
    tags: item.tags ?? [],
    is_technical: item.is_technical, requires_proctored_exam: item.requires_proctored_exam,
    certificate_issued: item.certificate_issued, certification_name: item.certification_name ?? "",
    exam_code: item.exam_code ?? "", exam_duration: item.exam_duration ?? "",
    passing_score: item.passing_score ?? null, exam_provider: item.exam_provider ?? "",
    validity_years: item.validity_years ?? null, renewal_required: item.renewal_required,
    cpe_credits: item.cpe_credits ?? null,
    training_provider_type: item.training_provider_type ?? "",
    certifying_body_id: item.certifying_body?.id ?? null,
    vendor_partner_id: item.vendor_partner?.id ?? null,
    product_ids: (item.products ?? []).map(p => p.id),
    oem_compliance_required: !!item.oem_compliance,
    oem_compliance_headcount: item.oem_compliance?.required_headcount ?? null,
    oem_compliance_notes: item.oem_compliance?.notes ?? "",
    competency_ids: (item.competencies ?? []).map(c => c.id),
  });
  editingItemId.value = item.id;
  editingTrainingCode.value = item.training_code;
  competencyToAdd.value = "";
  examProviderIsCustom.value = !!item.exam_provider && !EXAM_PROVIDER_OPTIONS.includes(item.exam_provider);
  // Seed chip names immediately from what the item already carries, then
  // replace with the vendor's full product list once it loads (so the
  // "add another product" dropdown has more than just what's selected).
  productToAdd.value = "";
  vendorProducts.value = item.products ?? [];
  if (itemForm.vendor_partner_id) loadVendorProducts(itemForm.vendor_partner_id);
  itemError.value = "";
  showItemDrawer.value = true;
}

async function saveItem(): Promise<void> {
  if (!itemForm.title.trim()) { itemError.value = "Title is required."; return; }
  if (!itemForm.training_provider_type) { itemError.value = "Training Provider Type is required."; return; }
  if (itemForm.training_provider_type === "certifying_body" && !itemForm.certifying_body_id) {
    itemError.value = "Choose the certifying professional body."; return;
  }
  if (itemForm.training_provider_type === "vendor_oem") {
    if (!itemForm.vendor_partner_id) { itemError.value = "Choose the vendor."; return; }
    if (itemForm.product_ids.length === 0) { itemError.value = "Select at least one product covered by this training."; return; }
  }
  itemSaving.value = true; itemError.value = "";
  try {
    if (editingItemId.value) {
      await api("PUT", `/api/v1/training/catalog/${editingItemId.value}`, { org: org(), body: { ...itemForm } });
    } else {
      await api("POST", "/api/v1/training/catalog", { org: org(), body: { ...itemForm } });
    }
    showItemDrawer.value = false;
    await Promise.all([loadCatalog(editingItemId.value ? catalogPage.value : 1), loadStats()]);
  } catch (e) {
    itemError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save that item.";
  } finally { itemSaving.value = false; }
}

async function deleteItem(item: TrainingItem, e?: Event): Promise<void> {
  e?.stopPropagation();
  if (!confirm(`Delete "${item.title}"? This cannot be undone.`)) return;
  try {
    await api("DELETE", `/api/v1/training/catalog/${item.id}`, { org: org() });
    await Promise.all([loadCatalog(catalogPage.value), loadStats()]);
  } catch (e) {
    catalogError.value = e instanceof Error ? e.message : "Could not delete that item.";
  }
}

// ── Competencies ─────────────────────────────────────────────────────────────
const competencies = ref<CompetencyItem[]>([]);
const competenciesLoading = ref(false);
const competenciesError   = ref("");

const filteredCompetencies = computed(() => {
  const term = search.value.trim().toLowerCase();
  if (!term) return competencies.value;
  return competencies.value.filter(c =>
    c.name.toLowerCase().includes(term) || (c.description ?? "").toLowerCase().includes(term));
});

async function loadCompetencies(): Promise<void> {
  if (!org()) return;
  competenciesLoading.value = true; competenciesError.value = "";
  try {
    const res = await api<Paginator<CompetencyItem>>("GET", "/api/v1/training/competencies", {
      org: org(), params: { per_page: 100 },
    });
    competencies.value = res.data;
  } catch (e) {
    competenciesError.value = e instanceof Error ? e.message : "Could not load competencies.";
  } finally { competenciesLoading.value = false; }
}

// ── Add Competence drawer ───────────────────────────────────────────────────
const showCompetenceDrawer = ref(false);
const competenceSaving = ref(false);
const competenceError  = ref("");
const competenceForm = reactive({ name: "", description: "", category: "" });

function openAddCompetence(): void {
  Object.assign(competenceForm, { name: "", description: "", category: "" });
  competenceError.value = "";
  showCompetenceDrawer.value = true;
}

async function saveCompetence(): Promise<void> {
  if (!competenceForm.name.trim()) { competenceError.value = "Name is required."; return; }
  competenceSaving.value = true; competenceError.value = "";
  try {
    await api("POST", "/api/v1/training/competencies", {
      org: org(),
      body: {
        name: competenceForm.name,
        description: competenceForm.description || null,
        category: competenceForm.category || null,
      },
    });
    showCompetenceDrawer.value = false;
    await loadCompetencies();
  } catch (e) {
    competenceError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not save that.";
  } finally { competenceSaving.value = false; }
}

// ── Assign a Training drawer ────────────────────────────────────────────────
const showAssignDrawer  = ref(false);
const assignTrainingId  = ref<number | "">("");
const assignSearch      = ref("");
const assignSelected    = ref<Set<number>>(new Set());
const assignSaving      = ref(false);
const assignError       = ref("");
const assignEmployees   = ref<Employee[]>([]);
const assignEmployeesLoaded = ref(false);

const assignFilteredEmployees = computed(() => {
  const term = assignSearch.value.trim().toLowerCase();
  if (!term) return assignEmployees.value;
  return assignEmployees.value.filter(e =>
    (e.full_name ?? "").toLowerCase().includes(term)
    || (e.employee_number ?? "").toLowerCase().includes(term));
});

const assignAllSelected = computed(() =>
  assignFilteredEmployees.value.length > 0
  && assignFilteredEmployees.value.every(e => assignSelected.value.has(e.id)));

function toggleAssignAll(): void {
  const next = new Set(assignSelected.value);
  if (assignAllSelected.value) {
    for (const e of assignFilteredEmployees.value) next.delete(e.id);
  } else {
    for (const e of assignFilteredEmployees.value) next.add(e.id);
  }
  assignSelected.value = next;
}

function toggleAssignOne(id: number): void {
  const next = new Set(assignSelected.value);
  next.has(id) ? next.delete(id) : next.add(id);
  assignSelected.value = next;
}

async function openAssign(prefillItemId?: number): Promise<void> {
  assignTrainingId.value = prefillItemId ?? "";
  assignSearch.value = "";
  assignSelected.value = new Set();
  assignError.value = "";
  showAssignDrawer.value = true;
  if (!assignEmployeesLoaded.value) {
    try {
      const res = await api<{ data: Employee[] } | Employee[]>(
        "GET", "/api/v1/employees", { org: org(), params: { per_page: 500, status: "active" } });
      assignEmployees.value = Array.isArray(res) ? res : (res.data ?? []);
      assignEmployeesLoaded.value = true;
    } catch { assignEmployees.value = []; }
  }
}

async function submitAssign(): Promise<void> {
  if (!assignTrainingId.value) { assignError.value = "Choose a training item."; return; }
  if (assignSelected.value.size === 0) { assignError.value = "Select at least one employee."; return; }

  assignSaving.value = true; assignError.value = "";
  try {
    const res = await api<{ message: string }>("POST", "/api/v1/training/records/bulk-assign", {
      org: org(),
      body: { training_item_id: assignTrainingId.value, employee_ids: Array.from(assignSelected.value) },
    });
    showAssignDrawer.value = false;
    await loadStats();
    // Said once, plainly — how many actually landed versus how many were
    // already enrolled, since "assigned" and "already had it" are different
    // outcomes for the person who asked.
    window.alert(res.message);
  } catch (e) {
    assignError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not assign that training.";
  } finally { assignSaving.value = false; }
}

// ── Budget requests: list + approve/reject ─────────────────────────────────
const budgetRequests = ref<TrainingBudgetRequestSummary[]>([]);
const budgetRequestsLoading = ref(false);
const budgetRequestsError = ref("");

async function loadBudgetRequests(): Promise<void> {
  if (!org()) return;
  budgetRequestsLoading.value = true; budgetRequestsError.value = "";
  try {
    const res = await api<{ data: TrainingBudgetRequestSummary[] }>("GET", "/api/v1/training/budget-requests", { org: org() });
    budgetRequests.value = res.data;
  } catch (e) {
    budgetRequestsError.value = e instanceof Error ? e.message : "Could not load budget requests.";
  } finally { budgetRequestsLoading.value = false; }
}

function budgetStatusLabel(status: string): string {
  return { pending_manager: "Pending Manager", pending_finance: "Pending Finance", approved: "Approved", rejected: "Rejected" }[status] ?? status;
}

function budgetStatusBadge(status: string): string {
  return { pending_manager: "badge-amber", pending_finance: "badge-amber", approved: "badge-green", rejected: "badge-gray" }[status] ?? "badge-gray";
}

async function approveBudgetRequest(req: TrainingBudgetRequestSummary): Promise<void> {
  const stage = req.status === "pending_finance" ? "approve-finance" : "approve-manager";
  try {
    await api("PATCH", `/api/v1/training/budget-requests/${req.id}/${stage}`, { org: org() });
    await loadBudgetRequests();
  } catch (e) {
    window.alert(e instanceof ApiError ? (e.firstError ?? e.message) : "Could not approve that request.");
  }
}

async function rejectBudgetRequest(req: TrainingBudgetRequestSummary): Promise<void> {
  const reason = window.prompt("Reason for rejecting this budget request:");
  if (!reason) return;
  try {
    await api("PATCH", `/api/v1/training/budget-requests/${req.id}/reject`, { org: org(), body: { reason } });
    await loadBudgetRequests();
  } catch (e) {
    window.alert(e instanceof ApiError ? (e.firstError ?? e.message) : "Could not reject that request.");
  }
}

// ── New Budget Request drawer ───────────────────────────────────────────────
const showBudgetDrawer = ref(false);
const budgetSaving = ref(false);
const budgetFormError = ref("");
const budgetThreshold = ref<TrainingBudgetThreshold>({ approval_threshold_amount: null, approval_threshold_currency: "KES" });

const budgetForm = reactive({
  mode: "by_training" as "by_training" | "by_employee",
  training_item_id: null as number | null,
  employee_id: null as number | null,
  payment_instructions: "",
  currency: "KES",
});

const budgetLineByEmployee = reactive<Record<number, { cost: number; achieve_by_date: string }>>({});
const budgetEmployeeSearch = ref("");

const budgetFilteredEmployees = computed(() => {
  const term = budgetEmployeeSearch.value.trim().toLowerCase();
  if (!term) return assignEmployees.value;
  return assignEmployees.value.filter(e =>
    (e.full_name ?? "").toLowerCase().includes(term)
    || (e.employee_number ?? "").toLowerCase().includes(term));
});

function toggleBudgetEmployee(emp: Employee): void {
  if (budgetLineByEmployee[emp.id]) {
    delete budgetLineByEmployee[emp.id];
    return;
  }
  const training = catalogItems.value.find(t => t.id === budgetForm.training_item_id);
  budgetLineByEmployee[emp.id] = { cost: training?.estimated_cost ?? 0, achieve_by_date: "" };
}

function onBudgetTrainingChange(): void {
  const training = catalogItems.value.find(t => t.id === budgetForm.training_item_id);
  for (const id of Object.keys(budgetLineByEmployee)) {
    budgetLineByEmployee[Number(id)].cost = training?.estimated_cost ?? 0;
  }
  if (training) budgetForm.currency = training.cost_currency;
}

interface BudgetTrainingRow { key: number; training_item_id: number | ""; cost: number; achieve_by_date: string }
const budgetTrainingRows = ref<BudgetTrainingRow[]>([]);
let budgetRowKeySeq = 0;

function addBudgetTrainingRow(): void {
  budgetTrainingRows.value.push({ key: budgetRowKeySeq++, training_item_id: "", cost: 0, achieve_by_date: "" });
}

function removeBudgetTrainingRow(key: number): void {
  budgetTrainingRows.value = budgetTrainingRows.value.filter(r => r.key !== key);
}

function onBudgetRowTrainingChange(row: BudgetTrainingRow): void {
  const training = catalogItems.value.find(t => t.id === row.training_item_id);
  if (training) {
    row.cost = training.estimated_cost;
    budgetForm.currency = training.cost_currency;
  }
}

const budgetTotal = computed(() => {
  if (budgetForm.mode === "by_training") {
    return Object.values(budgetLineByEmployee).reduce((sum, l) => sum + (l.cost || 0), 0);
  }
  return budgetTrainingRows.value.reduce((sum, r) => sum + (r.cost || 0), 0);
});

const budgetNeedsFinance = computed(() => {
  const threshold = budgetThreshold.value.approval_threshold_amount;
  return threshold !== null && budgetTotal.value > threshold;
});

async function loadBudgetThreshold(): Promise<void> {
  if (!org()) return;
  try {
    const res = await api<{ data: TrainingBudgetThreshold }>("GET", "/api/v1/training/budget-requests/settings", { org: org() });
    budgetThreshold.value = res.data;
  } catch { /* keep the default: no threshold configured */ }
}

function openBudgetDrawer(): void {
  Object.assign(budgetForm, {
    mode: "by_training", training_item_id: null, employee_id: null,
    payment_instructions: "", currency: "KES",
  });
  for (const id of Object.keys(budgetLineByEmployee)) delete budgetLineByEmployee[Number(id)];
  budgetTrainingRows.value = [];
  budgetEmployeeSearch.value = "";
  budgetFormError.value = "";
  showBudgetDrawer.value = true;
  if (!assignEmployeesLoaded.value) {
    api<{ data: Employee[] } | Employee[]>("GET", "/api/v1/employees", { org: org(), params: { per_page: 500, status: "active" } })
      .then(res => { assignEmployees.value = Array.isArray(res) ? res : (res.data ?? []); assignEmployeesLoaded.value = true; })
      .catch(() => { assignEmployees.value = []; });
  }
}

async function submitBudgetRequest(): Promise<void> {
  budgetFormError.value = "";
  if (!budgetForm.payment_instructions.trim()) { budgetFormError.value = "Payment instructions are required."; return; }

  type LineItem = { employee_id: number; training_item_id: number; cost: number; achieve_by_date: string | null };
  let items: LineItem[];
  const body: Record<string, unknown> = {
    mode: budgetForm.mode,
    payment_instructions: budgetForm.payment_instructions,
    currency: budgetForm.currency,
  };

  if (budgetForm.mode === "by_training") {
    if (!budgetForm.training_item_id) { budgetFormError.value = "Choose a training."; return; }
    const employeeIds = Object.keys(budgetLineByEmployee).map(Number);
    if (employeeIds.length === 0) { budgetFormError.value = "Select at least one employee."; return; }
    items = employeeIds.map(id => ({
      employee_id: id, training_item_id: budgetForm.training_item_id as number,
      cost: budgetLineByEmployee[id].cost, achieve_by_date: budgetLineByEmployee[id].achieve_by_date || null,
    }));
    body.training_item_id = budgetForm.training_item_id;
  } else {
    if (!budgetForm.employee_id) { budgetFormError.value = "Choose an employee."; return; }
    if (budgetTrainingRows.value.length === 0) { budgetFormError.value = "Add at least one training."; return; }
    if (budgetTrainingRows.value.some(r => !r.training_item_id)) { budgetFormError.value = "Choose a training for every row."; return; }
    items = budgetTrainingRows.value.map(r => ({
      employee_id: budgetForm.employee_id as number, training_item_id: r.training_item_id as number,
      cost: r.cost, achieve_by_date: r.achieve_by_date || null,
    }));
    body.employee_id = budgetForm.employee_id;
  }
  body.items = items;

  budgetSaving.value = true;
  try {
    await api("POST", "/api/v1/training/budget-requests", { org: org(), body });
    showBudgetDrawer.value = false;
    await loadBudgetRequests();
  } catch (e) {
    budgetFormError.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not submit the budget request.";
  } finally { budgetSaving.value = false; }
}

// ── Init ─────────────────────────────────────────────────────────────────
async function init(): Promise<void> {
  void loadStats();
  void loadCompetencies();
  void loadCertifyingBodies();
  void loadOemVendors();
  void loadBudgetSummary();
  void loadBudgetRequests();
  void loadBudgetThreshold();
  await loadCatalog(1);

  // Deep link from the item detail page's Edit button: ?edit=<id>.
  const editId = route.query.edit;
  if (editId) {
    const target = catalogItems.value.find(i => String(i.id) === String(editId));
    if (target) openEditItem(target);
    router.replace({ query: { ...route.query, edit: undefined } });
  }
}

onMounted(init);
watch(currentOrgSlug, init);
watch(panel, (next) => { router.replace({ query: { ...route.query, tab: next === "catalog" ? undefined : next } }); });
</script>
<style scoped>
/*
 * No own padding or max-width here — .app-content already applies a fluid
 * clamp() gutter (src/style.css). A fixed cap on top of that is what left a
 * capped-width column stranded in the middle of a wide monitor; matching
 * EmployeesView.vue's convention instead lets the grids below stretch to
 * fill whatever width is actually available.
 */
.tr-page {
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
}

.tr-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-4);
  flex-wrap: wrap;
}

.tr-header-actions {
  display: flex;
  gap: var(--space-3);
  flex-wrap: wrap;
}

.tr-header h2 {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
}

.tr-lead {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin-top: var(--space-1);
}

.tr-error {
  color: var(--color-error);
  font-size: var(--font-size-sm);
  margin-bottom: var(--space-3);
}

.tr-loading, .tr-empty {
  color: var(--color-text-muted);
  font-size: var(--font-size-sm);
  padding: var(--space-6) 0;
  text-align: center;
}

/* ── Stats strip ─────────────────────────────────────────────────────── */
.tr-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--space-4);
}

.tr-stat-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: var(--space-4) var(--space-5);
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  box-shadow: var(--shadow-sm);
}

.tr-stat-label {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-weight: var(--font-weight-medium);
}

.tr-stat-value {
  font-size: var(--font-size-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
}

.tr-stat-warn {
  color: var(--color-error);
}

.tr-stat-ring {
  align-items: center;
}

.tr-ring {
  --size: 56px;
  width: var(--size);
  height: var(--size);
  border-radius: 50%;
  background: conic-gradient(var(--color-brand-base) var(--pct), var(--color-border) var(--pct));
  display: flex;
  align-items: center;
  justify-content: center;
}

.tr-ring span {
  width: calc(var(--size) - 14px);
  height: calc(var(--size) - 14px);
  border-radius: 50%;
  background: var(--color-surface);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
}

/* ── Tabs ────────────────────────────────────────────────────────────── */
.tr-tabs {
  display: flex;
  gap: var(--space-2);
  border-bottom: 1px solid var(--color-border);
  overflow-x: auto;
}

.tr-tab {
  background: none;
  border: none;
  padding: var(--space-3) var(--space-4);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text-muted);
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  flex-shrink: 0;
  white-space: nowrap;
}

.tr-tab.active {
  color: var(--color-brand-base);
  border-bottom-color: var(--color-brand-base);
}

/* ── Toolbar ─────────────────────────────────────────────────────────── */
.tr-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
  flex-wrap: wrap;
}

.tr-toolbar-left {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex: 1;
  min-width: 200px;
}

.tr-toolbar-right {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
}

.tr-search {
  width: 100%;
  min-width: 160px;
  max-width: 320px;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
}

.tr-filters {
  display: flex;
  gap: var(--space-3);
  flex-wrap: wrap;
}

.tr-filters select {
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
}

/* ── Grids / cards ───────────────────────────────────────────────────── */
.tr-catalog-grid, .tr-competency-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: var(--space-4);
  margin-bottom: var(--space-5);
}

.catalog-card, .competency-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  padding: var(--space-5);
  cursor: pointer;
  transition: box-shadow var(--transition-base), border-color var(--transition-base);
  box-shadow: var(--shadow-sm);
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.competency-card {
  cursor: default;
}

.catalog-card:hover {
  border-color: var(--color-brand-base);
  box-shadow: var(--shadow-md);
}

.catalog-card-badges {
  display: flex;
  gap: var(--space-2);
  flex-wrap: wrap;
}

.catalog-card-title {
  font-size: var(--font-size-md);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
  line-height: var(--line-height-tight);
}

.catalog-card-desc {
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  line-height: var(--line-height-base);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  flex: 1;
}

.catalog-card-footer {
  display: flex;
  justify-content: space-between;
  gap: var(--space-2);
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  padding-top: var(--space-3);
  border-top: 1px solid var(--color-border);
}

.catalog-card-actions {
  display: flex;
  gap: var(--space-3);
  padding-top: var(--space-1);
}

.link-btn {
  background: none;
  border: none;
  padding: 0;
  font-size: var(--font-size-xs);
  color: var(--color-brand-base);
  cursor: pointer;
}

.link-btn-danger {
  color: var(--color-error);
}

.tr-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-4);
  font-size: var(--font-size-sm);
  color: var(--color-text-muted);
  margin-bottom: var(--space-6);
}

/* ── Drawers ─────────────────────────────────────────────────────────── */
.tr-drawer-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  z-index: 400;
  display: flex;
  justify-content: flex-end;
}

.tr-drawer {
  background: var(--color-surface);
  width: 100%;
  max-width: 480px;
  height: 100%;
  display: flex;
  flex-direction: column;
  box-shadow: -4px 0 24px rgba(0, 0, 0, 0.18);
}

.tr-drawer-wide {
  max-width: 640px;
}

.tr-drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-4) var(--space-5);
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;
}

.tr-drawer-header h3 {
  font-size: var(--font-size-md);
  font-weight: var(--font-weight-bold);
  color: var(--color-text);
}

.tr-drawer-close {
  background: none;
  border: none;
  font-size: 20px;
  line-height: 1;
  color: var(--color-text-muted);
  cursor: pointer;
}

.tr-drawer-body {
  padding: var(--space-5);
  overflow-y: auto;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.tr-drawer-body label {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-text);
}

.tr-drawer-body input[type="text"],
.tr-drawer-body input[type="number"],
.tr-drawer-body input[type="search"],
.tr-drawer-body select,
.tr-drawer-body textarea {
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  font-size: var(--font-size-sm);
  font-weight: var(--font-weight-regular);
}

.tr-drawer-body input:disabled {
  background: var(--color-surface-sunken);
  color: var(--color-text-muted);
}

.tr-field-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: var(--space-3);
}

.tr-field-group-label {
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-bold);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
  margin-top: var(--space-2);
}

.tr-checkbox {
  flex-direction: row !important;
  align-items: center;
  gap: var(--space-2) !important;
}

.tr-field-hint {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  margin-top: calc(var(--space-2) * -1);
}

.tr-inline-field {
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.tr-inline-field input {
  flex: 1;
}

.tr-chip-list {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-top: var(--space-2);
}

.tr-chip {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: var(--space-1) var(--space-2);
  background: var(--color-brand-subtle, #eef2ff);
  color: var(--color-brand-dark, var(--color-brand-base));
  border-radius: var(--radius-full, 999px);
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
}

.tr-chip button {
  background: none;
  border: none;
  padding: 0;
  margin-left: var(--space-1);
  font-size: var(--font-size-sm);
  line-height: 1;
  color: inherit;
  cursor: pointer;
  opacity: 0.7;
}

.tr-chip button:hover {
  opacity: 1;
}

.catalog-card-code {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  font-weight: var(--font-weight-medium);
  letter-spacing: 0.02em;
}

.tr-drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
  padding: var(--space-4) var(--space-5);
  border-top: 1px solid var(--color-border);
  flex-shrink: 0;
}

/* ── Assign Training employee table ─────────────────────────────────── */
.tr-assign-emp-header {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  flex-wrap: wrap;
}

.tr-assign-count {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

.tr-assign-table-wrap {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  max-height: 320px;
  overflow-y: auto;
  overflow-x: auto;
}

.tr-assign-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--font-size-sm);
}

.tr-assign-table th {
  position: sticky;
  top: 0;
  background: var(--color-surface-sunken);
  text-align: left;
  padding: var(--space-2) var(--space-3);
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border);
}

.tr-assign-table td {
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--color-border);
  cursor: pointer;
}

.tr-assign-table tr:last-child td {
  border-bottom: none;
}

.tr-assign-name {
  font-weight: var(--font-weight-medium);
  color: var(--color-text);
}

.tr-assign-email {
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
}

/* ── Training Budget ─────────────────────────────────────────────────── */
.tr-budget-currency-block {
  margin-bottom: var(--space-5);
}

.tr-budget-stats {
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}

.tr-budget-table-wrap {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow-x: auto;
  margin-bottom: var(--space-5);
}

.tr-budget-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--font-size-sm);
}

.tr-budget-table th {
  text-align: left;
  padding: var(--space-2) var(--space-3);
  font-size: var(--font-size-xs);
  color: var(--color-text-muted);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-sunken);
}

.tr-budget-table td {
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--color-border);
  cursor: pointer;
}

.tr-budget-table tr:last-child td {
  border-bottom: none;
}

.tr-budget-total-box {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: var(--space-3);
  padding: var(--space-3) var(--space-4);
  background: var(--color-brand-subtle, #eef2ff);
  border-radius: var(--radius-md);
  margin-top: var(--space-2);
}

.tr-budget-total-box strong {
  font-size: var(--font-size-lg, var(--font-size-md));
}

@media (max-width: 1024px) {
  .tr-stats { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
}

@media (max-width: 768px) {
  .tr-toolbar-left, .tr-toolbar-right { flex: 1 1 100%; }
  .tr-toolbar-right { justify-content: flex-start; }
  .tr-search { max-width: none; }
}

@media (max-width: 640px) {
  .tr-header { flex-direction: column; align-items: stretch; }
  .tr-header-actions { align-self: flex-start; }
  .tr-stats { grid-template-columns: repeat(2, 1fr); }
  .tr-toolbar-right .btn { flex: 1; }
  .tr-catalog-grid, .tr-competency-grid { grid-template-columns: 1fr; }
  .tr-drawer, .tr-drawer-wide { max-width: 100%; }
}

@media (max-width: 420px) {
  .tr-stats { grid-template-columns: 1fr; }
}
</style>
