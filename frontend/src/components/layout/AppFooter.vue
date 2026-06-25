<script setup>
// Global site footer — rendered once by AppLayout and shared across every route.
</script>

<template>
  <footer class="site-footer">
    <span class="ai-disclaimer">AI can make mistakes. Please verify important information.</span>
    <a
      href="https://www.paypal.com/donate/?hosted_button_id=WETP5RQ7ZGJ6U"
      target="_blank"
      rel="noopener noreferrer"
      class="donate-link"
    >
      ☕ Buy me a coffee
    </a>
  </footer>
</template>

<style scoped>
.site-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 1.25rem;
  padding: 1.25rem;
  border-top: 1px solid var(--border);
}
.ai-disclaimer {
  width: 100%;
  text-align: center;
  font-size: 0.78rem;
  color: var(--text-muted);
  opacity: 0.7;
}
.donate-link {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  color: var(--text-muted);
  text-decoration: none;
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: 999px;
  transition: color 0.15s, border-color 0.15s;
}
.donate-link:hover {
  color: var(--primary);
  border-color: var(--primary);
}
</style>
