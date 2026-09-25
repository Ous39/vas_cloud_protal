'use strict';
// Complaint Investigation: "View" opens a window with the request and response of one transaction,
// formatted for reading, with copy / download. Loaded from a file (not inline) so the CSP allows it
// and so the formatting can be unit-tested.

function prettyXml(xml) {
    const lines = xml.replace(/>\s+</g, '><').replace(/></g, '>\n<').split('\n');
    let indent = 0;
    const out = [];
    for (const line of lines) {
        if (/^<\//.test(line)) indent = Math.max(0, indent - 1);
        out.push('  '.repeat(indent) + line);
        // an opening tag on its own line (not self-closing, not a comment/declaration, not <a>text</a>)
        if (/^<[^!?\/][^<>]*>$/.test(line) && !/\/>$/.test(line)) indent++;
    }
    return out.join('\n');
}

function pretty(text) {
    const s = (text || '').trim();
    if (!s) return text || '';
    if (s[0] === '{' || s[0] === '[') {
        try { return JSON.stringify(JSON.parse(s), null, 2); } catch (e) { /* not JSON — fall through */ }
    }
    if (s[0] === '<') return prettyXml(s);
    return text;
}

if (typeof module !== 'undefined') module.exports = { pretty, prettyXml };

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        const $ = id => document.getElementById(id);
        const modalEl = $('txModal');
        let cur = null;

        async function copyText(text, btn) {
            try { await navigator.clipboard.writeText(text); }
            catch (e) {
                const ta = document.createElement('textarea');
                ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); } catch (e2) { /* nothing more to try */ }
                ta.remove();
            }
            if (btn) {
                const label = btn.dataset.label || btn.textContent;
                btn.dataset.label = label;
                btn.textContent = 'Copied!';
                setTimeout(() => { btn.textContent = label; }, 1200);
            }
        }

        function render() {
            if (!cur) return;
            const fmt = $('txFormat').checked, wrap = $('txWrap').checked;
            for (const [id, key] of [['txInput', 'input'], ['txOutput', 'output']]) {
                const pre = $(id);
                pre.textContent = fmt ? pretty(cur[key]) : cur[key];
                pre.style.whiteSpace = wrap ? 'pre-wrap' : 'pre';
                pre.style.wordBreak = wrap ? 'break-word' : 'normal';
            }
        }

        function shown() {
            return 'Transaction ' + cur.transaction_id + '\n' + [cur.create_date, cur.msisdn, cur.vendor, cur.channel].filter(Boolean).join(' | ') +
                '\nResult: ' + cur.result_status + ' ' + (cur.result_description || '') +
                '\n\n=== ' + ((cur.labels && cur.labels.input) || 'REQUEST (input)').toUpperCase() + ' ===\n' + $('txInput').textContent +
                '\n\n=== ' + ((cur.labels && cur.labels.output) || 'RESPONSE (output)').toUpperCase() + ' ===\n' + $('txOutput').textContent + '\n';
        }

        async function openTx(id, date, source) {
            cur = null;
            $('txTitle').textContent = 'Loading…';
            $('txMeta').textContent = '';
            $('txErr').classList.add('d-none');
            $('txInput').textContent = ''; $('txOutput').textContent = '';
            $('txNoteIn').textContent = ''; $('txNoteOut').textContent = '';
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            try {
                const resp = await fetch('?page=investigate_detail&id=' + encodeURIComponent(id) + '&d=' + encodeURIComponent(date || '') + (source ? '&source=' + encodeURIComponent(source) : ''), { credentials: 'same-origin' });
                const data = await resp.json();
                if (!resp.ok || data.error) throw new Error(data.error || 'Could not load this transaction.');
                cur = data;
                const lab = data.labels || {};
                $('txLblIn').textContent = lab.input || 'Request (input)';
                $('txLblOut').textContent = lab.output || 'Response (output)';
                $('txTitle').textContent = 'Transaction ' + (data.transaction_id || data.id);
                $('txMeta').textContent = [data.create_date, data.msisdn, data.vendor, data.channel, data.response_time != null ? data.response_time + ' ms' : ''].filter(Boolean).join('  •  ');
                const r = $('txResult');
                r.textContent = data.result_status + (data.result_description ? ' — ' + data.result_description : '');
                r.className = 'badge ' + (data.success ? 'bg-success' : 'bg-danger') + ' text-wrap text-start';
                const note = (cut, bad) => [cut ? 'Truncated for display — the CSV export has the full text.' : '', bad ? 'Contains bytes that are not valid text; shown as ?.' : ''].filter(Boolean).join(' ');
                $('txNoteIn').textContent = note(data.input_truncated, data.input_binary);
                $('txNoteOut').textContent = note(data.output_truncated, data.output_binary);
                render();
            } catch (e) {
                $('txTitle').textContent = 'Could not load';
                const err = $('txErr'); err.textContent = e instanceof SyntaxError ? 'Could not load — your session may have expired. Reload the page and sign in again.' : (e.message || String(e)); err.classList.remove('d-none');
            }
        }

        document.addEventListener('click', e => {
            const c = e.target.closest('[data-copy]');
            if (c) { e.preventDefault(); copyText(c.dataset.copy, c); return; }
            const v = e.target.closest('[data-tx-view]');
            if (v) { openTx(v.dataset.id, v.dataset.date, v.dataset.source); return; }
            const a = e.target.closest('[data-tx-action]');
            if (!a || !cur) return;
            const act = a.dataset.txAction;
            if (act === 'copy-input') copyText($('txInput').textContent, a);
            else if (act === 'copy-output') copyText($('txOutput').textContent, a);
            else if (act === 'copy-both') copyText(shown(), a);
            else if (act === 'download') {
                const url = URL.createObjectURL(new Blob([shown()], { type: 'text/plain;charset=utf-8' }));
                const link = document.createElement('a');
                link.href = url; link.download = 'transaction_' + String(cur.transaction_id || cur.id).replace(/[^A-Za-z0-9._-]/g, '_') + '.txt';
                document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
            }
        });
        if (modalEl) { $('txFormat').addEventListener('change', render); $('txWrap').addEventListener('change', render); }
    });
}
