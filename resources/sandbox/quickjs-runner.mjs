// QuickJS sandbox runner for the workflow `script.run` step.
//
// Reads a JSON request `{code, input, timeout_ms, memory_bytes}` from stdin,
// evaluates `code` inside a QuickJS WASM isolate and writes a JSON response
// `{ok:true, value}` or `{ok:false, error}` to stdout.
//
// The isolate has NO ambient authority by construction: no require/import, no
// network, no filesystem, no host globals. The only data crossing in is the
// JSON-serialised `input`; the only data crossing out is the JSON-serialisable
// return value. Wall-clock time and heap are hard-capped so a malicious or
// buggy script cannot hang or exhaust the worker.

import { readFileSync } from 'node:fs';
import { getQuickJS, shouldInterruptAfterDeadline } from 'quickjs-emscripten';

function respond(obj) {
    process.stdout.write(JSON.stringify(obj));
}

function clamp(value, min, max, fallback) {
    const n = Number(value);
    if (!Number.isFinite(n)) return fallback;
    return Math.min(Math.max(n, min), max);
}

let request;
try {
    request = JSON.parse(readFileSync(0, 'utf8') || '{}');
} catch (e) {
    respond({ ok: false, error: 'invalid runner input: ' + (e && e.message ? e.message : String(e)) });
    process.exit(0);
}

const code = typeof request.code === 'string' ? request.code : '';
const input = request.input ?? null;
const timeoutMs = clamp(request.timeout_ms, 50, 10000, 2000);
const memoryBytes = clamp(request.memory_bytes, 1024 * 1024, 256 * 1024 * 1024, 64 * 1024 * 1024);

const QuickJS = await getQuickJS();
const runtime = QuickJS.newRuntime();
runtime.setMemoryLimit(memoryBytes);
runtime.setMaxStackSize(320 * 1024);
runtime.setInterruptHandler(shouldInterruptAfterDeadline(Date.now() + timeoutMs));

const vm = runtime.newContext();

try {
    const handle = vm.newString(JSON.stringify(input ?? null));
    vm.setProp(vm.global, '__inputJson__', handle);
    handle.dispose();

    // The user code is run as a function body with `input` in scope, so a
    // top-level `return` yields the step's output.
    const wrapped = '(function(input){\n' + code + '\n})(JSON.parse(globalThis.__inputJson__))';
    const result = vm.evalCode(wrapped);

    if (result.error) {
        const dumped = vm.dump(result.error);
        result.error.dispose();
        const message = dumped && typeof dumped === 'object'
            ? dumped.message || JSON.stringify(dumped)
            : String(dumped);
        respond({ ok: false, error: message });
    } else {
        const dumped = vm.dump(result.value);
        result.value.dispose();
        let value;
        try {
            value = JSON.parse(JSON.stringify(dumped ?? null));
        } catch {
            respond({ ok: false, error: 'script return value is not JSON-serialisable' });
            value = undefined;
        }
        if (value !== undefined) {
            respond({ ok: true, value });
        }
    }
} catch (e) {
    respond({ ok: false, error: e && e.message ? e.message : String(e) });
} finally {
    vm.dispose();
    runtime.dispose();
}
