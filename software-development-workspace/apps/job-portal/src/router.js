import { createRouter, createWebHistory } from 'vue-router'
import JobListView from './views/JobListView.vue'
import JobDetailView from './views/JobDetailView.vue'
import ApplicationSuccessView from './views/ApplicationSuccessView.vue'

const routes = [
  { path: '/', name: 'home', component: JobListView },
  { path: '/jobs/:uuid', name: 'job-detail', component: JobDetailView },
  { path: '/jobs/:uuid/success', name: 'success', component: ApplicationSuccessView, props: true },
]

export default createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})
