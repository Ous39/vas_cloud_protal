<?php
// Shared by both Complaint Investigation sources; see txviewer.js.
?>
    <div class="modal fade" id="txModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header flex-wrap gap-2">
            <div class="me-auto"><h5 class="modal-title mb-0" id="txTitle">Transaction</h5><div class="text-muted small" id="txMeta"></div><span id="txResult" class="badge bg-secondary mt-1"></span></div>
            <div class="d-flex flex-wrap gap-3 align-items-center small">
                <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="txFormat" checked><label class="form-check-label" for="txFormat">Format JSON / XML</label></div>
                <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="txWrap" checked><label class="form-check-label" for="txWrap">Wrap lines</label></div>
                <button type="button" class="btn btn-sm btn-primary" data-tx-action="copy-both">Copy both</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-tx-action="download">Download .txt</button>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
        </div>
        <div class="modal-body">
            <div class="alert alert-danger d-none" id="txErr"></div>
            <div class="row g-3">
                <div class="col-lg-6"><div class="d-flex justify-content-between align-items-center mb-1"><strong id="txLblIn">Request (input)</strong><button type="button" class="btn btn-sm btn-outline-secondary" data-tx-action="copy-input">Copy</button></div><div class="text-muted small" id="txNoteIn"></div><pre class="border rounded p-2 bg-light mb-0" id="txInput" style="max-height:62vh;overflow:auto;font-size:.82rem"></pre></div>
                <div class="col-lg-6"><div class="d-flex justify-content-between align-items-center mb-1"><strong id="txLblOut">Response (output)</strong><button type="button" class="btn btn-sm btn-outline-secondary" data-tx-action="copy-output">Copy</button></div><div class="text-muted small" id="txNoteOut"></div><pre class="border rounded p-2 bg-light mb-0" id="txOutput" style="max-height:62vh;overflow:auto;font-size:.82rem"></pre></div>
            </div>
        </div>
    </div></div></div>
