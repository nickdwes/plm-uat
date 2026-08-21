<script setup lang="ts">
/**
 * What a customer sees.
 *
 * Four modes on one page — a ticket, their history, the catalogue, a survey —
 * because they share a shell and a token and differ only in what they render.
 *
 * Deliberately outside the staff application. No navigation, no organisation
 * switcher, nothing that hints at what else exists: this is reached by a link
 * in an email by somebody who does not work here, and the page should look like
 * it was written for them rather than like a corner of an internal tool.
 */
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import { safeHtml } from "../lib/richText";

const route = useRoute();
const token = computed(() => String(route.query.token ?? ""));
const mode  = computed(() => String(route.name ?? "portal-ticket"));

const busy    = ref(true);
const error   = ref("");
const ticket  = ref<any>(null);
const tickets = ref<any[]>([]);
const services = ref<any[]>([]);
const survey  = ref<any>(null);
const done    = ref("");

const reply = ref("");
const chosen = ref<any>(null);
const answers = ref<Record<string, string>>({});
const summary = ref("");
const rating = ref(0);

/*
 * Help before the form. The cheapest ticket is the one nobody needed to raise,
 * and the moment somebody is about to describe a problem is the only moment
 * they will read about it.
 */
const help = ref<any[]>([]);
const helpQuery = ref("");
const reading = ref<any>(null);
const rated = ref(false);
const surveyDraft = ref({ satisfaction: "", timeliness: "", resolved_fully: "", comment: "" });

const base = `${import.meta.env.VITE_LARAVEL_API_URL ?? ""}/api/v1/support/portal`;

/**
 * The portal talks to its own endpoints without the app's API client, which
 * attaches a bearer token and an organisation header. Neither exists here, and
 * sending them would be the start of treating this like an internal screen.
 */
async function call(path: string, options: RequestInit = {}): Promise<any> {
  const res = await fetch(base + path, {
    ...options,
    headers: { Accept: "application/json", "Content-Type": "application/json", ...(options.headers ?? {}) },
  });

  const body = await res.json().catch(() => ({}));

  if (!res.ok) {
    throw new Error(body.message ?? "Something went wrong. Please try again.");
  }

  return body;
}

async function load(): Promise<void> {
  busy.value = true; error.value = "";
  try {
    if (!token.value) throw new Error("This link is missing its code. Please use the link from your email.");

    if (mode.value === "portal-tickets") {
      tickets.value = (await call(`/tickets?token=${encodeURIComponent(token.value)}`)).data;
    } else if (mode.value === "portal-catalogue") {
      services.value = (await call(`/catalogue?token=${encodeURIComponent(token.value)}`)).data;
    } else if (mode.value === "portal-survey") {
      survey.value = (await call(`/survey?token=${encodeURIComponent(token.value)}`)).data;
    } else {
      ticket.value = (await call(`/ticket?token=${encodeURIComponent(token.value)}`)).data;
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : "This link is no longer valid.";
  } finally { busy.value = false; }
}

async function sendReply(): Promise<void> {
  if (!reply.value.trim()) return;
  busy.value = true; error.value = "";
  try {
    ticket.value = (await call("/reply", {
      method: "POST", body: JSON.stringify({ token: token.value, body: reply.value }),
    })).data;
    reply.value = "";
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not send that.";
  } finally { busy.value = false; }
}

async function findHelp(): Promise<void> {
  if (!helpQuery.value.trim()) { help.value = []; return; }
  try {
    help.value = (await call(
      `/help?token=${encodeURIComponent(token.value)}&q=${encodeURIComponent(helpQuery.value)}`,
    )).data;
  } catch { help.value = []; }
}

async function readArticle(slug: string): Promise<void> {
  busy.value = true;
  try {
    reading.value = (await call(
      `/article?token=${encodeURIComponent(token.value)}&slug=${encodeURIComponent(slug)}`,
    )).data;
    rated.value = false;
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not open that.";
  } finally { busy.value = false; }
}

/** Two counters and no comment box — a free-text field on an anonymous link
 *  is a moderation problem nobody asked for. */
async function rateArticle(helpful: boolean): Promise<void> {
  if (!reading.value) return;
  try {
    await call("/article/rate", {
      method: "POST",
      body: JSON.stringify({ token: token.value, slug: reading.value.slug, helpful }),
    });
    rated.value = true;
  } catch { rated.value = true; }
}

async function submitRequest(): Promise<void> {
  if (!chosen.value) return;
  busy.value = true; error.value = "";
  try {
    const res = await call("/request", {
      method: "POST",
      body: JSON.stringify({
        token: token.value, service: chosen.value.slug,
        summary: summary.value || null, answers: answers.value,
      }),
    });
    done.value = res.message;
    chosen.value = null; answers.value = {}; summary.value = "";
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not send that.";
  } finally { busy.value = false; }
}

async function submitSurvey(): Promise<void> {
  if (!rating.value) return;
  busy.value = true; error.value = "";
  try {
    const res = await call("/survey", {
      method: "POST",
      body: JSON.stringify({ token: token.value, rating: rating.value, ...surveyDraft.value }),
    });
    done.value = res.message;
    survey.value = { ...survey.value, answered: true };
  } catch (e) {
    error.value = e instanceof Error ? e.message : "Could not record that.";
  } finally { busy.value = false; }
}

function when(v: string | null): string {
  return v ? v.slice(0, 16).replace("T", " ") : "";
}

onMounted(load);
</script>

<template>
  <div class="pt-page">
    <div class="pt-shell">
      <header class="pt-head">
        <h1>Support</h1>
        <p v-if="ticket" class="pt-ref">{{ ticket.reference }}</p>
      </header>

      <p v-if="error" class="pt-error">{{ error }}</p>
      <p v-if="busy" class="pt-quiet">Loading…</p>
      <p v-if="done" class="pt-done">{{ done }}</p>

      <!-- ── One ticket ──────────────────────────────────────────────────── -->
      <template v-if="mode === 'portal-ticket' && ticket">
        <h2 class="pt-subject">{{ ticket.subject }}</h2>
        <p class="pt-meta">
          <span class="pt-status">{{ ticket.status }}</span>
          <span v-if="ticket.opened_at"> · opened {{ when(ticket.opened_at) }}</span>
        </p>

        <dl v-if="ticket.answers?.length" class="pt-answers">
          <template v-for="a in ticket.answers" :key="a.label">
            <dt>{{ a.label }}</dt><dd>{{ a.value || "—" }}</dd>
          </template>
        </dl>

        <div class="pt-thread">
          <div v-for="(m, i) in ticket.messages" :key="i" :class="['pt-msg', { us: m.from_us }]">
            <div class="pt-msg-head">
              <strong>{{ m.from }}</strong>
              <span>{{ when(m.created_at) }}</span>
            </div>
            <!--
              The customer reading their own correspondence back. Sanitised
              with more care than anywhere else in the application: this is
              content that arrived by email, rendered on a page reachable
              without signing in.
            -->
            <div class="pt-msg-body rich" v-html="safeHtml(m.body)"></div>
          </div>
        </div>

        <div v-if="ticket.state !== 'closed'" class="pt-compose">
          <textarea v-model="reply" rows="4" placeholder="Add to this request…"></textarea>
          <button class="pt-btn" :disabled="busy || !reply.trim()" @click="sendReply">Send</button>
        </div>
        <p v-else class="pt-quiet">This request is closed. Reply to our email to reopen it.</p>
      </template>

      <!-- ── Their history ───────────────────────────────────────────────── -->
      <template v-if="mode === 'portal-tickets'">
        <h2 class="pt-subject">Your requests</h2>
        <ul class="pt-list">
          <li v-for="t in tickets" :key="t.reference">
            <span class="pt-ref-sm">{{ t.reference }}</span>
            <span class="pt-list-subject">{{ t.subject }}</span>
            <span class="pt-status">{{ t.status }}</span>
          </li>
          <li v-if="!tickets.length && !busy" class="pt-quiet">Nothing raised yet.</li>
        </ul>
      </template>

      <!-- ── The catalogue ───────────────────────────────────────────────── -->
      <template v-if="mode === 'portal-catalogue'">
        <!-- Reading an article, rather than raising anything. -->
        <template v-if="reading">
          <button class="pt-back" @click="reading = null">← Back</button>
          <h2 class="pt-subject">{{ reading.title }}</h2>
          <p v-if="reading.summary" class="pt-meta">{{ reading.summary }}</p>
          <div class="pt-article rich" v-html="safeHtml(reading.body)"></div>
          <div v-if="!rated" class="pt-rate">
            <span>Did this help?</span>
            <button class="pt-btn-ghost" @click="rateArticle(true)">Yes</button>
            <button class="pt-btn-ghost" @click="rateArticle(false)">No</button>
          </div>
          <p v-else class="pt-quiet">Thank you.</p>
        </template>

        <template v-else-if="!chosen">
          <h2 class="pt-subject">Before you ask</h2>
          <label class="pt-field">
            <span>Describe the problem in a few words</span>
            <input v-model="helpQuery" placeholder="vpn keeps disconnecting" @keyup.enter="findHelp" />
          </label>
          <ul v-if="help.length" class="pt-help">
            <li v-for="a in help" :key="a.slug">
              <button @click="readArticle(a.slug)">
                <strong>{{ a.title }}</strong>
                <span>{{ a.summary }}</span>
              </button>
            </li>
          </ul>
          <p v-else-if="helpQuery && !busy" class="pt-quiet">
            Nothing written about that yet — pick what you need below.
          </p>

          <h2 class="pt-subject" style="margin-top:14px">Or tell us what you need</h2>
          <div class="pt-cards">
            <button v-for="s in services" :key="s.slug" class="pt-card" @click="chosen = s; done = ''">
              <strong>{{ s.name }}</strong>
              <span>{{ s.summary }}</span>
              <!--
                Said before they start, because "why has nothing happened" is
                the question an unexplained approval step generates.
              -->
              <span v-if="s.needs_approval" class="pt-approval">Needs approval</span>
            </button>
            <p v-if="!services.length && !busy" class="pt-quiet">Nothing to request just now.</p>
          </div>
        </template>

        <template v-else>
          <button class="pt-back" @click="chosen = null">← Everything else</button>
          <h2 class="pt-subject">{{ chosen.name }}</h2>
          <p class="pt-meta">{{ chosen.summary }}</p>

          <label class="pt-field">
            <span>A short title</span>
            <input v-model="summary" :placeholder="chosen.name" />
          </label>

          <label v-for="f in chosen.fields" :key="f.key" class="pt-field">
            <span>{{ f.label }}<em v-if="f.required"> · required</em></span>
            <textarea v-if="f.type === 'textarea'" v-model="answers[f.key]" rows="3"></textarea>
            <select v-else-if="f.type === 'select'" v-model="answers[f.key]">
              <option value="">Choose…</option>
              <option v-for="o in (f.options ?? [])" :key="o" :value="o">{{ o }}</option>
            </select>
            <input v-else v-model="answers[f.key]" :type="f.type === 'number' ? 'number'
              : f.type === 'date' ? 'date' : f.type === 'email' ? 'email' : 'text'" />
          </label>

          <button class="pt-btn" :disabled="busy" @click="submitRequest">Send this request</button>
        </template>
      </template>

      <!-- ── The survey ──────────────────────────────────────────────────── -->
      <template v-if="mode === 'portal-survey' && survey">
        <h2 class="pt-subject">How did we do?</h2>
        <p class="pt-meta">{{ survey.reference }} · {{ survey.subject }}</p>

        <p v-if="survey.answered" class="pt-quiet">
          Thank you — you have already answered this one.
        </p>

        <template v-else>
          <div class="pt-stars" role="radiogroup" aria-label="Rating out of five">
            <button v-for="n in 5" :key="n" :class="['pt-star', { on: n <= rating }]"
                    :aria-label="`${n} out of 5`" :aria-checked="rating === n" role="radio"
                    @click="rating = n">★</button>
          </div>

          <!--
            Three specific judgements rather than one number. A single score
            tells you somebody was unhappy and nothing about what to change.
          -->
          <div v-for="q in [
            { key: 'satisfaction',   label: 'Were you happy with the outcome?' },
            { key: 'timeliness',     label: 'Did it take a reasonable time?' },
            { key: 'resolved_fully', label: 'Was it fully resolved?' },
          ]" :key="q.key" class="pt-field">
            <span>{{ q.label }}</span>
            <div class="pt-choices">
              <label v-for="opt in ['yes', 'partly', 'no']" :key="opt">
                <input type="radio" :value="opt" v-model="surveyDraft[q.key as keyof typeof surveyDraft]" />
                <span>{{ opt }}</span>
              </label>
            </div>
          </div>

          <label class="pt-field">
            <span>Anything you would like to add?</span>
            <textarea v-model="surveyDraft.comment" rows="3"></textarea>
          </label>

          <button class="pt-btn" :disabled="busy || !rating" @click="submitSurvey">Send</button>
        </template>
      </template>

      <footer class="pt-foot">
        If this link stops working, reply to our email and we will send another.
      </footer>
    </div>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.pt-page { min-height: 100vh; background: var(--color-surface-sunken); padding: 32px 16px; }
.pt-shell { max-width: 44rem; margin: 0 auto; background: var(--color-surface);
  border-radius: 14px; padding: 26px 28px 20px;
  border: 1px solid var(--color-border); display: flex; flex-direction: column; gap: 12px; }

.pt-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px;
  padding-bottom: 12px; border-bottom: 1px solid var(--color-border); }
.pt-head h1 { margin: 0; font-size: 1.1rem; font-weight: 600; color: var(--color-brand-base); }
.pt-ref, .pt-ref-sm { font-family: ui-monospace, monospace; font-size: .8rem; color: var(--color-text-light); }

.pt-subject { margin: 4px 0 0; font-size: 1.15rem; font-weight: 600; color: var(--color-text); }
.pt-meta { margin: 0; font-size: .84rem; color: var(--color-text-light); }
.pt-status { display: inline-block; font-size: .74rem; padding: 2px 9px; border-radius: 999px;
  background: var(--color-brand-subtle); color: var(--color-brand-base); }

.pt-answers { display: grid; grid-template-columns: 12rem 1fr; gap: 5px 14px; margin: 8px 0 0;
  font-size: .86rem; padding: 12px; border-radius: 10px; background: var(--color-surface-sunken); }
.pt-answers dt { color: var(--color-text-light); }
.pt-answers dd { margin: 0; color: var(--color-text); }

.pt-thread { display: flex; flex-direction: column; gap: 10px; margin-top: 8px; }
.pt-msg { border-radius: 10px; padding: 11px 13px; background: var(--color-surface-sunken); }
.pt-msg.us { background: var(--color-brand-subtle); }
.pt-msg-head { display: flex; justify-content: space-between; gap: 10px;
  font-size: .76rem; color: var(--color-text-light); margin-bottom: 4px; }
.pt-msg-body { font-size: .9rem; color: var(--color-text); }

.pt-compose { display: flex; flex-direction: column; gap: 8px; margin-top: 6px; }
.pt-compose textarea, .pt-field input, .pt-field textarea, .pt-field select {
  width: 100%; font-family: inherit; font-size: .9rem; padding: 9px 11px;
  border: 1px solid var(--color-border-strong); border-radius: 9px;
  background: var(--color-surface); color: var(--color-text); }

.pt-btn { align-self: flex-end; padding: 9px 20px; border: none; border-radius: 9px;
  background: var(--color-brand-base); color: var(--color-on-brand);
  font-size: .88rem; cursor: pointer; }
.pt-btn:disabled { opacity: .5; cursor: default; }

.pt-list { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.pt-list li { display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
  padding: 11px 13px; border-radius: 10px; background: var(--color-surface-sunken); font-size: .88rem; }
.pt-list-subject { flex: 1 1 12rem; color: var(--color-text); }

.pt-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 10px; margin-top: 8px; }
.pt-card { display: flex; flex-direction: column; gap: 3px; text-align: left; cursor: pointer;
  padding: 14px; border-radius: 11px; border: 1px solid var(--color-border);
  background: var(--color-surface); font-size: .86rem; color: var(--color-text); }
.pt-card:hover { border-color: var(--color-brand-base); }
.pt-card span { font-size: .78rem; color: var(--color-text-light); }
.pt-approval { color: var(--color-warning) !important; }
.pt-back { align-self: flex-start; background: none; border: none; cursor: pointer;
  color: var(--color-brand-base); font-size: .84rem; padding: 0; }

.pt-field { display: flex; flex-direction: column; gap: 5px; margin-top: 8px; }
.pt-field > span { font-size: .82rem; font-weight: 600; color: var(--color-text); }
.pt-field em { font-style: normal; font-weight: 400; color: var(--color-text-light); }
.pt-choices { display: flex; gap: 14px; font-size: .86rem; }
.pt-choices label { display: inline-flex; align-items: center; gap: 5px; cursor: pointer; }

.pt-stars { display: flex; gap: 4px; margin: 6px 0; }
.pt-star { background: none; border: none; cursor: pointer; font-size: 1.9rem; line-height: 1;
  color: var(--color-border-strong); padding: 0; }
.pt-star.on { color: #f59e0b; }

.pt-error { font-size: .88rem; color: var(--color-error); background: var(--color-error-light);
  border-radius: 9px; padding: 11px 13px; margin: 0; }
.pt-done { font-size: .88rem; color: var(--color-success); background: var(--color-success-light);
  border-radius: 9px; padding: 11px 13px; margin: 0; }
.pt-quiet { font-size: .86rem; color: var(--color-text-light); margin: 0; }

.pt-foot { margin-top: 10px; padding-top: 12px; border-top: 1px solid var(--color-border);
  font-size: .78rem; color: var(--color-text-muted); }

.pt-article { font-size: .92rem; line-height: 1.6; color: var(--color-text);
  padding: 14px; border-radius: 10px; background: var(--color-surface-sunken); margin-top: 6px; }
.pt-rate { display: flex; align-items: center; gap: 10px; margin-top: 10px; font-size: .86rem; }
.pt-btn-ghost { padding: 5px 14px; border-radius: 8px; cursor: pointer;
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  color: var(--color-text); font-size: .84rem; }
.pt-help { list-style: none; margin: 6px 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.pt-help button { width: 100%; text-align: left; cursor: pointer; padding: 11px 13px;
  border-radius: 10px; border: 1px solid var(--color-border); background: var(--color-surface);
  display: flex; flex-direction: column; gap: 2px; font-size: .88rem; color: var(--color-text); }
.pt-help button:hover { border-color: var(--color-brand-base); }
.pt-help span { font-size: .78rem; color: var(--color-text-light); }
</style>
