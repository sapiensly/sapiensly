<script setup lang="ts">
/**
 * Renders one generated document — the user guide or the technical sheet.
 *
 * The server sends structure, not markup: a list of blocks with a `type` and
 * their content (see App\Services\Apps\Docs\Doc). Everything that decides how a
 * paragraph, a step list or a table LOOKS is here, so the same document can be
 * a page, a Markdown download and a string handed to a model without three
 * copies of the writing.
 *
 * Unknown block types render nothing rather than a raw dump: a document is
 * something people read, and a new block shape arriving early should be
 * invisible, not broken.
 */
export interface DocBlock {
    type: string;
    text?: string;
    items?: (string | { k: string; v: string } | { depth: number; text: string; meta?: string })[];
    head?: string[];
    rows?: string[][];
}

export interface DocSection {
    id: string;
    heading: string;
    body: DocBlock[];
}

defineProps<{ sections: DocSection[] }>();

function isKv(item: unknown): item is { k: string; v: string } {
    return typeof item === 'object' && item !== null && 'k' in item;
}

function isTreeItem(
    item: unknown,
): item is { depth: number; text: string; meta?: string } {
    return typeof item === 'object' && item !== null && 'depth' in item;
}
</script>

<template>
    <div class="space-y-10">
        <section
            v-for="section in sections"
            :key="section.id"
            :id="`doc-${section.id}`"
            class="scroll-mt-24"
        >
            <h2
                class="mb-4 border-b border-soft pb-2 text-[15px] font-semibold text-ink"
            >
                {{ section.heading }}
            </h2>

            <div class="space-y-4">
                <template v-for="(block, i) in section.body" :key="i">
                    <h3
                        v-if="block.type === 'h'"
                        class="pt-2 text-[13px] font-semibold tracking-wide text-ink uppercase"
                    >
                        {{ block.text }}
                    </h3>

                    <p
                        v-else-if="block.type === 'p'"
                        class="max-w-3xl text-sm leading-relaxed text-ink-muted"
                    >
                        {{ block.text }}
                    </p>

                    <p
                        v-else-if="block.type === 'note'"
                        class="max-w-3xl border-l-2 border-medium py-1 pl-3 text-xs leading-relaxed text-ink-subtle"
                    >
                        {{ block.text }}
                    </p>

                    <!-- The browser's own marker, not a hand-placed dot: one
                         positioned by hand sits a pixel or two high on every
                         line and there is no line-height it stays right for. -->
                    <ul
                        v-else-if="block.type === 'ul'"
                        class="max-w-3xl list-disc space-y-1.5 ps-5 marker:text-ink-subtle"
                    >
                        <li
                            v-for="(item, j) in block.items"
                            :key="j"
                            class="text-sm leading-relaxed text-ink-muted"
                        >
                            {{ item }}
                        </li>
                    </ul>

                    <!-- Numbered because the order is the instruction. -->
                    <ol
                        v-else-if="block.type === 'steps'"
                        class="max-w-3xl space-y-2"
                    >
                        <li
                            v-for="(item, j) in block.items"
                            :key="j"
                            class="flex gap-3 text-sm leading-relaxed text-ink"
                        >
                            <span
                                class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full border border-medium text-[11px] font-medium text-ink-muted"
                            >
                                {{ j + 1 }}
                            </span>
                            <span>{{ item }}</span>
                        </li>
                    </ol>

                    <dl
                        v-else-if="block.type === 'kv'"
                        class="max-w-3xl divide-y divide-soft/60 rounded-md border border-soft"
                    >
                        <div
                            v-for="(item, j) in block.items"
                            :key="j"
                            class="flex flex-wrap items-baseline gap-x-4 gap-y-0.5 px-3 py-2"
                        >
                            <dt class="min-w-40 text-xs text-ink-muted">
                                {{ isKv(item) ? item.k : '' }}
                            </dt>
                            <dd
                                class="font-mono text-xs break-all text-ink"
                            >
                                {{ isKv(item) ? item.v : '' }}
                            </dd>
                        </div>
                    </dl>

                    <!-- Wide tables scroll inside their own box; the page never
                         scrolls sideways. -->
                    <div
                        v-else-if="block.type === 'table'"
                        class="-mx-1 overflow-x-auto px-1"
                    >
                        <table class="w-full min-w-[32rem] text-sm">
                            <thead>
                                <tr
                                    class="border-b border-soft text-left text-xs tracking-wide text-ink-subtle uppercase"
                                >
                                    <th
                                        v-for="(cell, j) in block.head"
                                        :key="j"
                                        class="py-2 pr-4 font-medium"
                                    >
                                        {{ cell }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, j) in block.rows"
                                    :key="j"
                                    class="border-b border-soft/50"
                                >
                                    <td
                                        v-for="(cell, k) in row"
                                        :key="k"
                                        class="py-2 pr-4 align-top text-xs"
                                        :class="
                                            k === 0
                                                ? 'text-ink'
                                                : 'text-ink-muted'
                                        "
                                    >
                                        {{ cell }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div
                        v-else-if="block.type === 'tree'"
                        class="overflow-x-auto rounded-md border border-soft bg-elevated/40 px-3 py-2"
                    >
                        <div
                            v-for="(item, j) in block.items"
                            :key="j"
                            class="flex items-baseline gap-2 py-0.5 font-mono text-[11px] whitespace-nowrap"
                            :style="{
                                paddingLeft: `${(isTreeItem(item) ? item.depth : 0) * 14}px`,
                            }"
                        >
                            <span class="text-ink">{{
                                isTreeItem(item) ? item.text : ''
                            }}</span>
                            <span
                                v-if="isTreeItem(item) && item.meta"
                                class="text-ink-subtle"
                                >{{ item.meta }}</span
                            >
                        </div>
                    </div>
                </template>
            </div>
        </section>
    </div>
</template>
