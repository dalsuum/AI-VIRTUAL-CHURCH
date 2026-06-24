<script setup>
// Thin wrapper over @iconify/vue's <Icon>. All UI icons go through this so the
// offline registry (src/icons.js) is the only icon source and sizing/color are
// consistent. Icons inherit currentColor and default to 1.4em square.
import { Icon } from "@iconify/vue";

defineProps({
  // Iconify name, e.g. "mdi:home". Must be registered in src/icons.js.
  name: { type: String, required: true },
  // Any CSS size value; applied to both width and height.
  size: { type: String, default: "1.4em" },
});
</script>

<template>
  <Icon
    :icon="name"
    :width="size"
    :height="size"
    aria-hidden="true"
    focusable="false"
  />
</template>
