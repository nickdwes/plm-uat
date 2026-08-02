import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";
import { initTheme } from "./stores/theme";

import "./tokens.css";
import "./theme.css";
import "./style.css";

// Resolve the theme before mounting, so the first paint is already correct
// rather than flashing light and then correcting itself.
initTheme();

createApp(App).use(router).mount("#app");
