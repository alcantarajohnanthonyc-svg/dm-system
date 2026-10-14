<?php
// email_modals.php - Shared Modals Container (Standardized with Email Hub)
?>

<!-- MODAL 0: EMAIL HUB INPUT MODAL (PASTE / CSV) -->
<div id="emailHubInputModal" style="display: none;" class="fixed inset-0 z-[99998] flex items-center justify-center bg-black/60 backdrop-blur-md">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-3xl p-6 mx-4 flex flex-col max-h-[90vh]">
        <div class="flex justify-between items-center mb-6 pb-4 border-b">
            <div>
                <h2 class="text-base font-bold text-gray-800 uppercase tracking-wider flex items-center gap-2">
                    <i class="las la-mail-bulk text-amber-600 text-xl"></i> Email Hub
                </h2>
                <p class="text-[11px] text-gray-500">Manage bulk emailing via pasted accounts or CSV upload.</p>
            </div>
            <button type="button" onclick="closeEmailHubInputModal()" class="w-8 h-8 rounded-full bg-gray-100 font-bold hover:bg-gray-200">✕</button>
        </div>

        <div class="flex border-b border-gray-200 mb-6">
            <button type="button" onclick="switchEmailHubTab(1)" id="tabBtn1" class="flex-1 pb-3 text-xs font-bold text-amber-600 border-b-2 border-amber-600 uppercase">Paste Accounts / Emails</button>
            <button type="button" onclick="switchEmailHubTab(2)" id="tabBtn2" class="flex-1 pb-3 text-xs font-bold text-gray-400 border-b-2 border-transparent uppercase">Upload CSV</button>
        </div>

        <div id="emailHubTab1" class="space-y-4">
            <div>
                <div class="flex justify-between items-center mb-1">
                    <label class="block text-[11px] font-bold text-gray-600 uppercase">Accounts or Email List</label>
                    <span id="pasteItemCount" class="text-xs font-semibold text-blue-600">0 items found</span>
                </div>
                <textarea id="pasteAccountInput" rows="6" oninput="updateEmailHubPasteCount()" class="w-full p-3 border rounded-xl text-xs bg-gray-50 font-mono focus:outline-none focus:ring-2 focus:ring-slate-800" placeholder="Paste account numbers or emails per line..."></textarea>
            </div>
            <div class="grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-xl border">
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">START DATE (SEARCH FILTER DEFAULTS)</label>
                    <input type="date" id="hubStartDate" class="w-full p-2 border rounded-lg text-xs bg-white" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">END DATE (SEARCH FILTER DEFAULTS)</label>
                    <input type="date" id="hubEndDate" class="w-full p-2 border rounded-lg text-xs bg-white" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
                </div>
            </div>
            <button type="button" onclick="processPasteEmailHub()" class="w-full py-3 bg-[#1e293b] text-white rounded-xl text-xs font-bold hover:bg-slate-800 transition">Process and Review Emails</button>
        </div>

        <div id="emailHubTab2" class="space-y-4" style="display: none;">
            <div class="p-6 border-2 border-dashed rounded-2xl bg-gray-50 text-center py-8">
                <i class="las la-cloud-upload-alt text-4xl text-amber-600 mb-2"></i>
                <input type="file" id="csvEmailFile" accept=".csv" class="hidden" onchange="handleCsvFileSelect(this)">
                <button type="button" onclick="document.getElementById('csvEmailFile').click()" class="px-4 py-2 bg-white border text-xs font-semibold rounded-xl hover:bg-gray-100">Browse File</button>
                <span id="selectedCsvFileName" class="text-xs text-emerald-600 font-medium block mt-2"></span>
            </div>
            <button type="button" onclick="processCsvEmailHub()" class="w-full py-3 bg-[#1e293b] text-white rounded-xl text-xs font-bold hover:bg-slate-800 transition">Upload and Process CSV</button>
        </div>
    </div>
</div>

<!-- MODAL 1: BULK REVIEW & VALIDATION MODAL -->
<div id="bulkEmailReviewModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-md">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-6xl p-6 mx-4 flex flex-col max-h-[90vh]">
        
        <div class="flex justify-between items-start pb-4 border-b border-gray-100">
            <div>
                <h3 class="text-lg font-extrabold text-gray-900 flex items-center gap-2">
                    <i class="las la-envelope-open-text text-amber-600 text-xl"></i> Bulk Email Review & Validation
                </h3>
                <p id="bulkSummaryCount" class="text-xs text-gray-500 font-medium mt-0.5">0 accounts loaded for review</p>
            </div>
            <button type="button" onclick="closeBulkEmailReviewModal()" class="w-8 h-8 rounded-full bg-gray-100 font-bold hover:bg-gray-200">✕</button>
        </div>

        <div class="py-3 px-4 my-3 bg-slate-50 border border-slate-200/80 rounded-2xl flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg text-xs font-bold">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Ready: <span id="countReady">0</span>
                </span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-rose-50 text-rose-700 border border-rose-200 rounded-lg text-xs font-bold">
                    <span class="w-2 h-2 rounded-full bg-rose-500"></span> Missing Email: <span id="countMissingEmail">0</span>
                </span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-lg text-xs font-bold">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span> Without SOA: <span id="countMissingSOA">0</span>
                </span>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-gray-100 text-gray-600 border border-gray-200 rounded-lg text-xs font-bold">
                    <span class="w-2 h-2 rounded-full bg-gray-400"></span> No Data: <span id="countNoData">0</span>
                </span>
            </div>
        </div>

        <div class="border border-gray-200 rounded-2xl overflow-y-auto flex-grow max-h-[48vh] shadow-inner bg-white">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="bg-slate-100 sticky top-0 z-10 text-slate-700 font-bold uppercase text-[10px]">
                    <tr>
                        <th class="p-3 text-center w-28 cursor-pointer select-none" onclick="sortBulkTable('status')">Status</th>
                        <th class="p-3 cursor-pointer select-none" onclick="sortBulkTable('account')">Account Number & Company</th>
                        <th class="p-3 cursor-pointer select-none" onclick="sortBulkTable('coverage')">Coverage Dates</th>
                        <th class="p-3">Recipient Email</th>
                        <th class="p-3">CC Emails</th>
                        <th class="p-3 text-center w-24">Preview</th>
                    </tr>
                </thead>
                <tbody id="bulkReviewTableBody" class="divide-y divide-gray-100"></tbody>
            </table>
        </div>

        <div class="flex justify-end gap-3 mt-4 pt-3 border-t border-gray-100">
            <button type="button" onclick="closeBulkEmailReviewModal()" class="px-5 py-2.5 bg-gray-100 text-xs font-bold rounded-xl hover:bg-gray-200">Cancel</button>
            <button type="button" onclick="proceedBulkDispatchFromModal()" class="px-6 py-2.5 bg-emerald-600 text-white text-xs font-bold rounded-xl hover:bg-emerald-700 transition">Confirm & Send Emails</button>
        </div>
    </div>
</div>

<!-- MODAL 2: EMAIL PREVIEW & CUSTOMIZATION MODAL -->
<div id="emailPreviewModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-100 w-full max-w-3xl p-6 mx-4 max-h-[90vh] flex flex-col">
        <div class="flex justify-between items-center mb-4 pb-2 border-b">
            <h3 class="text-base font-bold text-gray-800">Email Preview & Customization</h3>
            <button type="button" onclick="closeEmailPreviewModal()" class="text-gray-400 hover:text-gray-600 font-bold">✕</button>
        </div>

        <form id="emailPreviewForm" class="flex flex-col flex-grow overflow-y-auto space-y-3" enctype="multipart/form-data">
            <input type="hidden" id="preview_dm_id" name="dm_ids" value="">
            <input type="hidden" id="preview_item_ids" name="item_ids" value="">
            <input type="hidden" id="preview_start_date" name="start_date" value="">
            <input type="hidden" id="preview_end_date" name="end_date" value="">
            
            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">To Email</label>
                <input type="email" id="preview_to" name="recipient_email" class="w-full p-2 border rounded-xl text-xs bg-gray-50" required>
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">CC Emails</label>
                <input type="text" id="preview_cc" name="cc_emails" class="w-full p-2 border rounded-xl text-xs bg-gray-50" placeholder="e.g. accounting@company.com">
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase">Subject</label>
                <input type="text" id="preview_subject" name="subject" class="w-full p-2 border rounded-xl text-xs bg-gray-50" required>
            </div>

            <div class="flex flex-col flex-grow">
                <div class="flex justify-between items-center mb-1">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase">Message Body</label>
                    <div class="flex gap-1 bg-gray-100 p-1 rounded-lg">
                        <button type="button" onclick="switchPreviewMode('visual')" id="btnVisualTab" class="px-3 py-1 bg-white shadow-xs text-xs font-bold text-blue-600 rounded-md">Visual Preview</button>
                        <button type="button" onclick="switchPreviewMode('code')" id="btnCodeTab" class="px-3 py-1 text-xs font-bold text-gray-600 rounded-md">HTML Code</button>
                    </div>
                </div>

                <div id="visualPreviewContainer" class="w-full p-4 border rounded-xl bg-white shadow-inner overflow-y-auto max-h-72"></div>
                <textarea id="preview_body" name="html_content" rows="8" oninput="syncVisualPreviewFromCode()" class="w-full p-3 border rounded-xl text-xs bg-gray-50 font-mono hidden" required></textarea>
            </div>

            <div>
                <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">Attachments</label>
                <div id="preview_attachments_container" class="p-2 border rounded-xl bg-gray-50 text-xs space-y-1 max-h-24 overflow-y-auto"></div>
            </div>

            <div class="flex justify-end gap-2 pt-3 border-t">
                <button type="button" onclick="closeEmailPreviewModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-xl text-xs font-bold hover:bg-gray-300">Cancel</button>
                <button type="button" onclick="submitConfirmedEmail()" class="px-5 py-2 bg-emerald-600 text-white rounded-xl text-xs font-bold hover:bg-emerald-700">Send Email Now</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: TERMINAL DISPATCH PROGRESS MODAL -->
<div id="dispatchProgressModal" style="display: none;" class="fixed inset-0 z-[99999] flex items-center justify-center bg-black/60 backdrop-blur-md p-4">
    <div class="bg-white rounded-3xl shadow-2xl border w-full max-w-4xl p-6 flex flex-col max-h-[85vh]">
        <div class="flex items-center justify-between pb-4 border-b border-gray-100 mb-4 shrink-0">
            <div>
                <h3 class="text-base font-extrabold text-gray-900">Dispatching Statement Emails</h3>
                <p class="text-[11px] text-gray-500">Processing batch dispatches...</p>
            </div>
            <span id="batchProgressCounter" class="text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 px-3 py-1.5 rounded-xl">Batch 0/0</span>
        </div>

        <div class="space-y-4">
            <div class="bg-slate-50 border p-4 rounded-2xl">
                <div class="flex justify-between items-center mb-2">
                    <span id="dispatchProgressText" class="text-sm font-extrabold text-slate-900">Initializing dispatch process...</span>
                    <span id="dispatchProgressPercent" class="text-xs font-extrabold text-emerald-600">0%</span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-3.5 overflow-hidden p-0.5 border">
                    <div id="dispatchProgressBar" class="bg-emerald-500 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
            </div>

            <div class="flex flex-col min-h-[260px] max-h-[320px] bg-slate-950 text-slate-200 text-xs font-mono p-4 rounded-2xl border overflow-hidden">
                <div id="dispatchProgressLog" class="flex-grow overflow-y-auto space-y-1.5 pr-2">
                    <div class="text-slate-500 italic">Waiting for batch dispatch...</div>
                </div>
            </div>
        </div>

        <div class="flex justify-end pt-4 mt-4 border-t border-gray-100">
            <button type="button" id="closeDispatchModalBtn" onclick="closeDispatchModal()" style="display:none;" class="px-6 py-2.5 bg-slate-900 text-white rounded-xl text-xs font-bold hover:bg-slate-800">Close & Reload Page</button>
        </div>
    </div>
</div>

<script>
let bulkAccountsCache = [];

function openEmailHubModal() { 
    const modal = document.getElementById('emailHubInputModal');
    if (modal) {
        const globalStart = document.querySelector('input[name="start_date"]')?.value || '';
        const globalEnd = document.querySelector('input[name="end_date"]')?.value || '';
        
        const hubStart = document.getElementById('hubStartDate');
        const hubEnd = document.getElementById('hubEndDate');
        
        if (hubStart && !hubStart.value) hubStart.value = globalStart;
        if (hubEnd && !hubEnd.value) hubEnd.value = globalEnd;

        modal.style.display = 'flex'; 
    }
}

function closeEmailHubInputModal() { document.getElementById('emailHubInputModal').style.display = 'none'; }
function closeBulkEmailReviewModal() { document.getElementById('bulkEmailReviewModal').style.display = 'none'; }
function closeEmailPreviewModal() { document.getElementById('emailPreviewModal').style.display = 'none'; }
function closeDispatchModal() { window.location.reload(); }

function switchEmailHubTab(tab) {
    const tab1 = document.getElementById('emailHubTab1');
    const tab2 = document.getElementById('emailHubTab2');
    const btn1 = document.getElementById('tabBtn1');
    const btn2 = document.getElementById('tabBtn2');

    if (tab === 1) {
        tab1.style.display = 'block';
        tab2.style.display = 'none';
        btn1.className = "flex-1 pb-3 text-xs font-bold text-amber-600 border-b-2 border-amber-600 uppercase";
        btn2.className = "flex-1 pb-3 text-xs font-bold text-gray-400 border-b-2 border-transparent uppercase";
    } else {
        tab1.style.display = 'none';
        tab2.style.display = 'block';
        btn2.className = "flex-1 pb-3 text-xs font-bold text-amber-600 border-b-2 border-amber-600 uppercase";
        btn1.className = "flex-1 pb-3 text-xs font-bold text-gray-400 border-b-2 border-transparent uppercase";
    }
}

function updateEmailHubPasteCount() {
    const text = document.getElementById('pasteAccountInput').value;
    const lines = text.split('\n').filter(line => line.trim() !== '');
    document.getElementById('pasteItemCount').innerText = `${lines.length} item${lines.length === 1 ? '' : 's'} found`;
}

function handleCsvFileSelect(input) {
    if (input.files && input.files[0]) {
        document.getElementById('selectedCsvFileName').innerText = "Selected File: " + input.files[0].name;
    }
}

function switchPreviewMode(mode) {
    const visualContainer = document.getElementById('visualPreviewContainer');
    const codeTextarea = document.getElementById('preview_body');
    const btnVisual = document.getElementById('btnVisualTab');
    const btnCode = document.getElementById('btnCodeTab');

    if (mode === 'visual') {
        visualContainer.style.display = 'block';
        codeTextarea.classList.add('hidden');
        btnVisual.className = "px-3 py-1 bg-white shadow-xs text-xs font-bold text-blue-600 rounded-md";
        btnCode.className = "px-3 py-1 text-xs font-bold text-gray-600 rounded-md";
        visualContainer.innerHTML = codeTextarea.value;
    } else {
        visualContainer.style.display = 'none';
        codeTextarea.classList.remove('hidden');
        btnCode.className = "px-3 py-1 bg-white shadow-xs text-xs font-bold text-blue-600 rounded-md";
        btnVisual.className = "px-3 py-1 text-xs font-bold text-gray-600 rounded-md";
    }
}

function syncVisualPreviewFromCode() {
    const codeTextarea = document.getElementById('preview_body');
    const visualContainer = document.getElementById('visualPreviewContainer');
    visualContainer.innerHTML = codeTextarea.value;
}

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}
</script>