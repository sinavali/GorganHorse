// Scans the local directory, provides a web UI to select files/folders,
// and generates a combined Markdown output file (latest.md by default).

const fs = require('fs');
const path = require('path');
const http = require('http');
const { exec } = require('child_process');

// ======================= Configuration ===========================
let outputFile = 'latest.md';
let pathPrefix = '/';
let PORT = 3456;

const excludeFolderPatterns = [
    /^node_modules$/i,
    /^bin$/i,
    /^obj$/i,
    /^CopiedHere$/i,
    /^\.vs$/i,
    /^\.git$/i,
    /^\.idea$/i,
    /^__pycache__$/i,
];

const excludeFilePatterns = [
    /^latest\.md$/i,
    /^inliner\.js$/i,
];

// ================================================================

function isExcludedFolder(name) {
    return excludeFolderPatterns.some(re => re.test(name));
}

function isExcludedFile(name) {
    return excludeFilePatterns.some(re => re.test(name));
}

function getFileExtension(fileName) {
    const ext = path.extname(fileName).toLowerCase();
    return ext ? ext.slice(1) : '';
}

// ---------- Binary detection (first 8000 bytes) ----------
function isBinaryFile(filePath) {
    const buffer = Buffer.alloc(8000);
    let fd;
    try {
        fd = fs.openSync(filePath, 'r');
        const bytesRead = fs.readSync(fd, buffer, 0, 8000, 0);
        fs.closeSync(fd);
        for (let i = 0; i < bytesRead; i++) {
            if (buffer[i] === 0) return true;
        }
        let nonPrintable = 0;
        for (let i = 0; i < bytesRead; i++) {
            const byte = buffer[i];
            if (byte < 9 || (byte > 10 && byte < 13) || (byte > 13 && byte < 32)) {
                nonPrintable++;
            }
        }
        return nonPrintable / bytesRead > 0.3;
    } catch (e) {
        return false;
    }
}

// ---------- Full recursive tree builder ----------
function buildDirectoryTree(dir, basePath = '') {
    const items = [];
    let entries;
    try {
        entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch (e) {
        return items;
    }

    for (const entry of entries) {
        if (entry.isDirectory()) {
            if (isExcludedFolder(entry.name)) continue;
            const relPath = basePath ? `${basePath}/${entry.name}` : entry.name;
            const children = buildDirectoryTree(path.join(dir, entry.name), relPath);
            // Count files in this subtree (only non‑binary, non‑excluded)
            let fileCount = 0;
            function count(d) {
                try {
                    const subs = fs.readdirSync(d, { withFileTypes: true });
                    for (const s of subs) {
                        if (s.isDirectory() && !isExcludedFolder(s.name)) {
                            count(path.join(d, s.name));
                        } else if (s.isFile() && !isExcludedFile(s.name) && !isBinaryFile(path.join(d, s.name))) {
                            fileCount++;
                        }
                    }
                } catch (e) {}
            }
            count(path.join(dir, entry.name));
            items.push({ type: 'folder', name: entry.name, path: relPath, fileCount, children });
        } else if (entry.isFile()) {
            if (isExcludedFile(entry.name)) continue;
            const relPath = basePath ? `${basePath}/${entry.name}` : entry.name;
            const fullPath = path.join(dir, entry.name);
            if (isBinaryFile(fullPath)) continue;   // skip binary files entirely
            let stat;
            try { stat = fs.statSync(fullPath); } catch (e) { stat = { size: 0 }; }
            const ext = path.extname(entry.name).toLowerCase();
            let charCount = 0;
            let lineCount = 0;
            try {
                const content = fs.readFileSync(fullPath, 'utf-8');
                charCount = content.length;
                lineCount = content.split(/\r?\n/).length;
            } catch (e) {}
            items.push({
                type: 'file',
                name: entry.name,
                path: relPath,
                size: stat.size,
                ext: ext ? ext.slice(1) : '',
                charCount,
                lineCount,
            });
        }
    }

    // Sort folders first, then files alphabetically
    items.sort((a, b) => {
        if (a.type === b.type) return a.name.localeCompare(b.name);
        return a.type === 'folder' ? -1 : 1;
    });
    return items;
}

// ======================= HTML Frontend ===========================
const htmlContent = `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LLM File Selector</title>
    <style>
        :root {
            --bg-main: #0d1117;
            --bg-card: #161b22;
            --bg-hover: #21262d;
            --text-main: #c9d1d9;
            --text-muted: #8b949e;
            --accent: #238636;
            --accent-hover: #2ea043;
            --accent-disabled: #1b4721;
            --border: #30363d;
            --folder-color: #e3b341;
            --file-color: #58a6ff;
            --guide-line: #30363d;
            --disabled-opacity: 0.5;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
            background: var(--bg-main);
            color: var(--text-main);
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }

        .header {
            width: 100%;
            max-width: 1000px;
            padding: 20px 20px 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 10px;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .header h1 {
            font-size: 1.6rem;
            font-weight: 600;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .header-profiles {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px;
            padding: 4px 0;
        }

        .btn {
            background: var(--bg-card);
            color: var(--text-main);
            border: 1px solid var(--border);
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn:hover { background: var(--bg-hover); border-color: #8b949e; }
        .btn-primary { background: var(--accent); color: white; border-color: var(--accent); }
        .btn-primary:hover:not(:disabled) { background: var(--accent-hover); }
        .btn:disabled { background: var(--accent-disabled); color: #888; cursor: not-allowed; }
        .btn-danger { background: #da3633; color: white; border-color: #da3633; }
        .btn-danger:hover { background: #f85149; }

        .profile-btn {
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-main);
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .profile-btn:hover { background: var(--bg-hover); border-color: #8b949e; }
        .profile-btn.active {
            border-color: var(--accent);
            background: var(--accent-disabled);
            color: white;
        }
        .profile-btn .del {
            margin-left: 4px;
            color: var(--text-muted);
            font-weight: bold;
        }
        .profile-btn .del:hover { color: #f85149; }

        .search-box {
            display: flex;
            align-items: center;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 4px 8px;
            gap: 4px;
        }
        .search-box input {
            border: none;
            outline: none;
            background: transparent;
            color: var(--text-main);
            font-size: 0.85rem;
            width: 180px;
        }

        .main-content {
            width: 100%;
            max-width: 1000px;
            padding: 0 20px 100px;
            flex: 1;
        }

        .tree-container {
            background: var(--bg-card);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 0.9rem;
        }

        ul { list-style: none; padding-left: 20px; margin: 0; position: relative; }
        .root-ul { padding-left: 0; }

        ul:not(.root-ul)::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 6px;
            width: 1px;
            background: var(--guide-line);
        }

        li { margin: 3px 0; position: relative; }

        .item-row {
            display: flex;
            align-items: center;
            padding: 4px 6px;
            border-radius: 6px;
            transition: background 0.15s;
            gap: 2px;
        }
        .item-row:hover { background: var(--bg-hover); }

        .folder-toggle {
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-muted);
            transition: transform 0.2s;
            margin-right: 2px;
            flex-shrink: 0;
            transform: rotate(90deg)
        }
        .collapsed > .item-row .folder-toggle { transform: rotate(0deg); }
        .collapsed > ul { display: none; }

        .icon { width: 14px; height: 14px; flex-shrink: 0; }
        .folder-icon { color: var(--folder-color); }
        .file-icon { color: var(--file-color); }

        label {
            cursor: pointer;
            display: flex;
            align-items: center;
            flex-grow: 1;
            user-select: none;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .folder-label { font-weight: 600; color: #fff; }
        .file-label { color: var(--text-main); }

        .file-size { color: var(--text-muted); font-size: 0.75rem; margin-left: 6px; white-space: nowrap; }
        .badge {
            background: var(--border);
            color: var(--text-muted);
            border-radius: 10px;
            padding: 0 5px;
            font-size: 0.7rem;
            margin-left: 6px;
        }

        input[type="checkbox"] {
            appearance: none;
            -webkit-appearance: none;
            width: 15px;
            height: 15px;
            border: 1px solid var(--text-muted);
            border-radius: 4px;
            background: var(--bg-main);
            cursor: pointer;
            margin-right: 6px;
            flex-shrink: 0;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.1s;
        }
        input[type="checkbox"]:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        input[type="checkbox"]:checked,
        input[type="checkbox"]:indeterminate {
            background: var(--accent);
            border-color: var(--accent);
        }
        input[type="checkbox"]:checked::after {
            content: '';
            width: 3px;
            height: 6px;
            border: solid white;
            border-width: 0 1.5px 1.5px 0;
            transform: rotate(45deg);
            margin-bottom: 1px;
        }
        input[type="checkbox"]:indeterminate::after {
            content: '';
            width: 6px;
            height: 2px;
            background: white;
            border-radius: 1px;
        }

        .action-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background: rgba(22, 27, 34, 0.85);
            backdrop-filter: blur(10px);
            border-top: 1px solid var(--border);
            padding: 12px 20px;
            display: flex;
            justify-content: center;
            z-index: 100;
        }

        .action-content {
            width: 100%;
            max-width: 1000px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .selection-stats {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        .selection-stats span { color: #fff; font-weight: bold; }

        #generate-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 8px 20px;
            font-size: 0.95rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        #generate-btn:hover:not(:disabled) { background: var(--accent-hover); }
        #generate-btn:active:not(:disabled) { transform: scale(0.98); }
        #generate-btn:disabled { background: var(--accent-disabled); color: #888; cursor: not-allowed; }

        .toast-container {
            position: fixed;
            bottom: 80px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 1000;
        }

        .toast {
            background: var(--bg-card);
            color: white;
            padding: 10px 18px;
            border-radius: 6px;
            border-left: 4px solid var(--accent);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            gap: 8px;
            transform: translateX(120%);
            transition: transform 0.3s ease;
        }
        .toast.show { transform: translateX(0); }
        .toast.error { border-left-color: #f85149; }

        /* Preview modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6);
            justify-content: center;
            align-items: center;
            z-index: 200;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            max-width: 700px;
            width: 90%;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }
        .modal-header {
            padding: 15px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-body {
            padding: 15px;
            overflow: auto;
            font-family: monospace;
            white-space: pre-wrap;
            font-size: 0.85rem;
            color: var(--text-main);
            background: var(--bg-main);
            flex: 1;
        }
        .modal-footer {
            padding: 10px 15px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
    </style>
</head>
<body>
<div class="header">
    <div class="header-top">
        <h1>
            <svg width="22" height="22" viewBox="0 0 16 16"><path fill="currentColor" fill-rule="evenodd" d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0113.25 16h-9.5A1.75 1.75 0 012 14.25V1.75zm1.75-.25a.25.25 0 00-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 00.25-.25V6h-2.75A1.75 1.75 0 019 4.25V1.5H3.75zm6.75 0V4.25c0 .138.112.25.25.25h2.75a.25.25 0 00-.177-.073l-2.823-2.823a.25.25 0 00-.073-.104z"/></svg>
            LLM File Selector
        </h1>
        <div class="header-actions">
            <div class="search-box">
                <svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" d="M11.742 10.344a6.5 6.5 0 10-1.397 1.398h-.001l3.85 3.85a1 1 0 001.415-1.414l-3.85-3.85zm-5.242.156a5 5 0 110-10 5 5 0 010 10z"/></svg>
                <input type="text" id="search-input" placeholder="Filter files...">
            </div>
            <button class="btn" id="btn-expand">Expand All</button>
            <button class="btn" id="btn-collapse">Collapse All</button>
            <button class="btn" id="btn-selall">Select All</button>
            <button class="btn" id="btn-clear">Clear</button>
            <button class="btn" id="btn-rescan">Rescan</button>
            <button class="btn btn-primary" id="btn-preview" disabled>Preview</button>
        </div>
    </div>
    <div class="header-profiles">
        <span style="font-size:0.8rem; color:var(--text-muted); margin-right:4px;">Profiles:</span>
        <div id="profile-list" style="display:flex; flex-wrap:wrap; gap:4px; align-items:center;"></div>
        <button class="btn" id="btn-save-profile" style="font-size:0.75rem;">+ Save Profile</button>
    </div>
</div>

<div class="main-content">
    <div class="tree-container" id="tree-container">
        <div style="text-align: center; color: var(--text-muted); padding: 40px 0;">Loading directory structure...</div>
    </div>
</div>

<div class="action-bar">
    <div class="action-content">
        <div class="selection-stats">
            <span id="file-count">0</span> files selected,
            <span id="char-count">0</span> chars,
            <span id="line-count">0</span> lines
        </div>
        <div style="display: flex; gap: 8px;">
            <input type="text" id="output-path" placeholder="output file" value="latest.md" style="background:var(--bg-card); border:1px solid var(--border); border-radius:4px; padding:4px 6px; color:var(--text-main); width:120px;">
            <input type="text" id="path-prefix" placeholder="prefix" value="/" style="background:var(--bg-card); border:1px solid var(--border); border-radius:4px; padding:4px 6px; color:var(--text-main); width:80px;">
            <button id="generate-btn" disabled>
                <svg width="14" height="14" viewBox="0 0 16 16"><path fill="currentColor" fill-rule="evenodd" d="M1.5 8a6.5 6.5 0 1113 0 6.5 6.5 0 01-13 0zM8 0a8 8 0 100 16A8 8 0 008 0zm.75 4.75a.75.75 0 00-1.5 0v2.5h-2.5a.75.75 0 000 1.5h2.5v2.5a.75.75 0 001.5 0v-2.5h2.5a.75.75 0 000-1.5h-2.5v-2.5z"/></svg>
                Generate
            </button>
        </div>
    </div>
</div>

<div class="toast-container" id="toast-container"></div>

<!-- Preview modal -->
<div class="modal-overlay" id="preview-modal">
    <div class="modal">
        <div class="modal-header">
            <strong id="modal-title">Preview: latest.md</strong>
            <button class="btn" id="modal-close">✕</button>
        </div>
        <div class="modal-body" id="preview-content"></div>
        <div class="modal-footer">
            <button class="btn" id="modal-close2">Close</button>
            <button class="btn" id="modal-download">Download</button>
            <button class="btn btn-primary" id="modal-generate">Generate Now</button>
        </div>
    </div>
</div>

<script>
    (() => {
        // Use the script's absolute path as a unique storage key per project
        const STORAGE_PREFIX = 'llm-file-selector_' + (window.SCRIPT_PATH || 'default');

        const treeContainer = document.getElementById('tree-container');
        const generateBtn = document.getElementById('generate-btn');
        const previewBtn = document.getElementById('btn-preview');
        const countSpan = document.getElementById('file-count');
        const charCountSpan = document.getElementById('char-count');
        const lineCountSpan = document.getElementById('line-count');
        const searchInput = document.getElementById('search-input');
        const outputPathInput = document.getElementById('output-path');
        const pathPrefixInput = document.getElementById('path-prefix');
        const previewModal = document.getElementById('preview-modal');
        const previewContent = document.getElementById('preview-content');
        const modalTitle = document.getElementById('modal-title');
        const downloadBtn = document.getElementById('modal-download');
        const profileList = document.getElementById('profile-list');
        const saveProfileBtn = document.getElementById('btn-save-profile');

        // Restore output/path inputs, default output to latest.md
        const savedOutput = localStorage.getItem(STORAGE_PREFIX + '_outputFile');
        outputPathInput.value = savedOutput || 'latest.md';
        const savedPrefix = localStorage.getItem(STORAGE_PREFIX + '_pathPrefix');
        pathPrefixInput.value = savedPrefix || '/';

        // Save them on change
        outputPathInput.addEventListener('input', () => {
            localStorage.setItem(STORAGE_PREFIX + '_outputFile', outputPathInput.value);
        });
        pathPrefixInput.addEventListener('input', () => {
            localStorage.setItem(STORAGE_PREFIX + '_pathPrefix', pathPrefixInput.value);
        });

        // Fixed icons with fill="currentColor"
        const icons = {
            folder: \`<svg class="icon folder-icon" viewBox="0 0 16 16"><path fill="currentColor" d="M1.75 1A1.75 1.75 0 000 2.75v10.5C0 14.216.784 15 1.75 15h12.5A1.75 1.75 0 0016 13.25v-8.5A1.75 1.75 0 0014.25 3H7.543l-1.6-1.6A1.75 1.75 0 004.706 1H1.75z"/></svg>\`,
            file: (ext) => {
                let color = 'var(--file-color)';
                const codeExts = ['js','ts','jsx','tsx','py','rb','java','c','cpp','h','cs','go','rs','swift','kt','css','scss','html','xml','json','yaml','yml','md','txt'];
                if (codeExts.includes(ext)) color = '#79c0ff';
                return \`<svg class="icon" style="color:\${color}" viewBox="0 0 16 16"><path fill="currentColor" d="M2 1.75C2 .784 2.784 0 3.75 0h5.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v8.586A1.75 1.75 0 0112.25 15h-8.5A1.75 1.75 0 012 13.25V1.75z"/></svg>\`;
            },
            arrow: \`<svg width="12" height="12" viewBox="0 0 16 16"><path fill="currentColor" d="M6.22 3.22a.75.75 0 011.06 0l4.25 4.25a.75.75 0 010 1.06l-4.25 4.25a.75.75 0 01-1.06-1.06L9.94 8 6.22 4.28a.75.75 0 010-1.06z"/></svg>\`,
        };

        let lastSelectedCheckbox = null;
        let activeProfile = null; // name of currently applied profile

        // ---------- Profile management ----------
        function loadProfiles() {
            const data = localStorage.getItem(STORAGE_PREFIX + '_profiles');
            if (data) {
                try { return JSON.parse(data); } catch (e) { return []; }
            }
            return [];
        }

        function saveProfiles(profiles) {
            localStorage.setItem(STORAGE_PREFIX + '_profiles', JSON.stringify(profiles));
        }

        function renderProfiles() {
            const profiles = loadProfiles();
            profileList.innerHTML = '';
            profiles.forEach((p, idx) => {
                const btn = document.createElement('button');
                btn.className = 'profile-btn' + (activeProfile === p.name ? ' active' : '');
                btn.innerHTML = \`\${p.name} <span class="del" data-index="\${idx}">×</span>\`;
                btn.title = \`Files: \${p.files.length}, prefix: \${p.prefix || '/'}, output: \${p.output || 'latest.md'}\`;
                btn.addEventListener('click', (e) => {
                    if (e.target.classList.contains('del')) return; // handled by del click
                    applyProfile(p);
                });
                // Delete button
                const del = btn.querySelector('.del');
                del.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (confirm(\`Delete profile "\${p.name}"?\`)) {
                        const profiles2 = loadProfiles();
                        const idx2 = parseInt(del.dataset.index);
                        profiles2.splice(idx2, 1);
                        saveProfiles(profiles2);
                        if (activeProfile === p.name) activeProfile = null;
                        renderProfiles();
                        showToast(\`Profile "\${p.name}" deleted.\`);
                    }
                });
                profileList.appendChild(btn);
            });
        }

        function applyProfile(profile) {
            // Clear all selections
            document.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
                cb.indeterminate = false;
            });

            // Check files from profile
            profile.files.forEach(filePath => {
                const cb = document.querySelector(\`.file-cb[value="\${filePath}"]\`);
                if (cb && !cb.disabled) {
                    cb.checked = true;
                    updateParentCheckboxes(cb);
                }
            });

            // Set prefix and output
            if (profile.prefix !== undefined) pathPrefixInput.value = profile.prefix;
            if (profile.output !== undefined) outputPathInput.value = profile.output;

            activeProfile = profile.name;
            renderProfiles();
            updateStatus();
            showToast(\`Profile "\${profile.name}" applied.\`);
        }

        function saveCurrentAsProfile() {
            const selected = Array.from(document.querySelectorAll('.file-cb:checked')).map(cb => cb.value);
            if (selected.length === 0) {
                showToast('No files selected to save.', 'error');
                return;
            }
            const prefix = pathPrefixInput.value.trim() || '/';
            const output = outputPathInput.value.trim() || 'latest.md';
            const name = prompt('Enter profile name:');
            if (!name) return;
            const profiles = loadProfiles();
            const existing = profiles.find(p => p.name === name);
            if (existing) {
                if (!confirm(\`Profile "\${name}" already exists. Overwrite?\`)) return;
                existing.files = selected;
                existing.prefix = prefix;
                existing.output = output;
            } else {
                profiles.push({ name, files: selected, prefix, output });
            }
            saveProfiles(profiles);
            activeProfile = name;
            renderProfiles();
            showToast(\`Profile "\${name}" saved.\`);
        }

        // ---------- Build tree from full data ----------
        function buildHtmlTree(items, parentPath = '') {
            const ul = document.createElement('ul');
            for (const item of items) {
                if (item.type === 'folder') {
                    const li = document.createElement('li');
                    const relPath = parentPath ? \`\${parentPath}/\${item.name}\` : item.name;
                    li.dataset.path = relPath;
                    li.innerHTML = \`
                        <div class="item-row">
                            <div class="folder-toggle">\${icons.arrow}</div>
                            <input type="checkbox" class="folder-cb">
                            \${icons.folder}
                            <label class="folder-label">\${item.name}</label>
                            <span class="badge">\${item.fileCount}</span>
                        </div>
                    \`;
                    li.classList.add('collapsed'); // start collapsed
                    const childrenUl = buildHtmlTree(item.children, relPath);
                    li.appendChild(childrenUl);
                    ul.appendChild(li);
                } else {
                    const li = document.createElement('li');
                    const relPath = parentPath ? \`\${parentPath}/\${item.name}\` : item.name;
                    li.dataset.path = relPath;
                    const sizeStr = item.size < 1024 ? \`\${item.size} B\` :
                                    item.size < 1048576 ? \`\${(item.size/1024).toFixed(1)} KB\` :
                                    \`\${(item.size/1048576).toFixed(1)} MB\`;
                    const iconHtml = icons.file(item.ext);
                    li.innerHTML = \`
                        <div class="item-row">
                            <span style="width: 18px;"></span>
                            <input type="checkbox" class="file-cb" value="\${relPath}"
                                   data-charcount="\${item.charCount || 0}"
                                   data-linecount="\${item.lineCount || 0}">
                            \${iconHtml}
                            <label class="file-label" title="\${item.name}">\${item.name}</label>
                            <span class="file-size">\${sizeStr}</span>
                        </div>
                    \`;
                    ul.appendChild(li);
                }
            }
            return ul;
        }

        // ---------- Init tree ----------
        async function loadFullTree() {
            try {
                const res = await fetch('/api/tree');
                const tree = await res.json();
                treeContainer.innerHTML = '';
                const rootUl = buildHtmlTree(tree);
                rootUl.className = 'root-ul';
                treeContainer.appendChild(rootUl);
                attachGlobalEvents();
                restoreTreeState();
                restoreSelection();
                updateStatus();
                renderProfiles();
            } catch (err) {
                treeContainer.innerHTML = '<div style="color:#f85149; text-align:center;">Failed to load directory tree.</div>';
            }
        }

        // ---------- Persistence ----------
        function saveTreeState() {
            const expanded = Array.from(document.querySelectorAll('li:not(.collapsed)')).map(li => li.dataset.path);
            localStorage.setItem(STORAGE_PREFIX + '_treeExpanded', JSON.stringify(expanded));
        }

        function restoreTreeState() {
            const saved = localStorage.getItem(STORAGE_PREFIX + '_treeExpanded');
            if (!saved) return;
            const paths = JSON.parse(saved);
            paths.forEach(p => {
                const li = document.querySelector(\`li[data-path="\${p}"]\`);
                if (li && li.classList.contains('collapsed')) {
                    li.classList.remove('collapsed');
                }
            });
        }

        function saveSelection() {
            const selected = Array.from(document.querySelectorAll('.file-cb:checked')).map(cb => cb.value);
            localStorage.setItem(STORAGE_PREFIX + '_selectedFiles', JSON.stringify(selected));
        }

        function restoreSelection() {
            const saved = localStorage.getItem(STORAGE_PREFIX + '_selectedFiles');
            if (!saved) return;
            const files = JSON.parse(saved);
            files.forEach(path => {
                const cb = document.querySelector(\`.file-cb[value="\${path}"]\`);
                if (cb) cb.checked = true;
            });
            // Update parent checkboxes after restore
            document.querySelectorAll('.file-cb:checked').forEach(cb => updateParentCheckboxes(cb));
        }

        // ---------- Checkbox logic ----------
        function updateParentCheckboxes(checkbox) {
            let parentLi = checkbox.closest('ul').closest('li');
            while (parentLi) {
                const parentCb = parentLi.querySelector('.folder-cb');
                const ul = parentLi.querySelector('ul');
                if (!ul) break;
                const childCbs = Array.from(ul.querySelectorAll(':scope > li > .item-row > input[type="checkbox"]'));
                const allChecked = childCbs.every(cb => cb.checked || cb.disabled);
                const someChecked = childCbs.some(cb => cb.checked || cb.indeterminate);
                parentCb.checked = allChecked;
                parentCb.indeterminate = !allChecked && someChecked;
                parentLi = parentLi.closest('ul').closest('li');
            }
        }

        function updateStatus() {
            const checked = document.querySelectorAll('.file-cb:checked');
            const count = checked.length;
            countSpan.textContent = count;

            let totalChars = 0;
            let totalLines = 0;
            checked.forEach(cb => {
                totalChars += parseInt(cb.dataset.charcount) || 0;
                totalLines += parseInt(cb.dataset.linecount) || 0;
            });

            charCountSpan.textContent = totalChars.toLocaleString();
            lineCountSpan.textContent = totalLines.toLocaleString();

            const hasSelection = count > 0;
            generateBtn.disabled = !hasSelection;
            previewBtn.disabled = !hasSelection;
            saveSelection();
        }

        // ---------- Toast ----------
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = \`toast \${type}\`;
            toast.textContent = message;
            container.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // ---------- Preview & Modal ----------
        async function showPreview(files, prefix) {
            const res = await fetch('/api/preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ files, prefix })
            });
            if (!res.ok) throw new Error('Preview failed');
            const text = await res.text();
            previewContent.textContent = text;
            modalTitle.textContent = \`Preview: \${outputPathInput.value.trim() || 'latest.md'}\`;
            previewModal.dataset.content = text;
            previewModal.dataset.filename = outputPathInput.value.trim() || 'latest.md';
            previewModal.classList.add('active');
        }

        function closeModal() {
            previewModal.classList.remove('active');
        }

        function downloadPreview() {
            const content = previewModal.dataset.content;
            const filename = previewModal.dataset.filename;
            if (!content) return;
            const blob = new Blob([content], { type: 'text/markdown' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        // ---------- Generate ----------
        async function generateFile() {
            const selected = Array.from(document.querySelectorAll('.file-cb:checked'))
                                  .map(cb => cb.value);
            if (selected.length === 0) {
                showToast('No files selected.', 'error');
                return;
            }
            const output = outputPathInput.value.trim() || 'latest.md';
            const prefix = pathPrefixInput.value.trim() || '/';
            const originalText = generateBtn.innerHTML;
            generateBtn.disabled = true;
            generateBtn.innerHTML = 'Generating...';

            try {
                const res = await fetch('/api/generate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ files: selected, output, prefix })
                });
                if (!res.ok) throw new Error('Generation failed');
                showToast(\`\${output} generated with \${selected.length} files.\`, 'success');
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                generateBtn.disabled = false;
                generateBtn.innerHTML = originalText;
                updateStatus();
            }
        }

        // ---------- Event binding ----------
        function attachGlobalEvents() {
            // Toggle folders
            treeContainer.addEventListener('click', (e) => {
                const toggle = e.target.closest('.folder-toggle');
                if (toggle) {
                    e.stopPropagation();
                    const li = toggle.closest('li');
                    if (li) {
                        li.classList.toggle('collapsed');
                        saveTreeState();
                    }
                }
            });

            // Checkbox changes
            treeContainer.addEventListener('change', (e) => {
                if (e.target.type !== 'checkbox') return;
                if (e.target.classList.contains('folder-cb')) {
                    const li = e.target.closest('li');
                    const ul = li.querySelector('ul');
                    if (ul) {
                        ul.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                            if (!cb.disabled) {
                                cb.checked = e.target.checked;
                                cb.indeterminate = false;
                            }
                        });
                    }
                }
                updateParentCheckboxes(e.target);
                updateStatus();
                // If any checkbox changed, clear active profile indicator
                activeProfile = null;
                renderProfiles();
            });

            // Shift+click range select
            treeContainer.addEventListener('click', (e) => {
                if (e.target.type === 'checkbox' && e.target.classList.contains('file-cb') && !e.target.disabled) {
                    if (e.shiftKey && lastSelectedCheckbox) {
                        const checkboxes = Array.from(document.querySelectorAll('.file-cb:not(:disabled)'));
                        const start = checkboxes.indexOf(lastSelectedCheckbox);
                        const end = checkboxes.indexOf(e.target);
                        if (start !== -1 && end !== -1) {
                            const [low, high] = [Math.min(start, end), Math.max(start, end)];
                            for (let i = low; i <= high; i++) {
                                checkboxes[i].checked = true;
                                updateParentCheckboxes(checkboxes[i]);
                            }
                        }
                    }
                    lastSelectedCheckbox = e.target;
                    updateStatus();
                }
            });

            // Buttons
            document.getElementById('btn-expand').addEventListener('click', () => {
                document.querySelectorAll('li.collapsed').forEach(li => li.classList.remove('collapsed'));
                saveTreeState();
            });
            document.getElementById('btn-collapse').addEventListener('click', () => {
                document.querySelectorAll('li:not(.collapsed)').forEach(li => {
                    if (li.querySelector('ul')) li.classList.add('collapsed');
                });
                saveTreeState();
            });
            document.getElementById('btn-selall').addEventListener('click', () => {
                document.querySelectorAll('.file-cb:not(:disabled)').forEach(cb => {
                    cb.checked = true;
                    updateParentCheckboxes(cb);
                });
                updateStatus();
                activeProfile = null;
                renderProfiles();
            });
            document.getElementById('btn-clear').addEventListener('click', () => {
                document.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    cb.checked = false;
                    cb.indeterminate = false;
                });
                updateStatus();
                activeProfile = null;
                renderProfiles();
            });
            document.getElementById('btn-rescan').addEventListener('click', () => {
                loadFullTree().then(() => showToast('Directory rescanned'));
            });

            // Search
            searchInput.addEventListener('input', () => {
                const term = searchInput.value.toLowerCase();
                document.querySelectorAll('.item-row').forEach(row => {
                    const label = row.querySelector('label');
                    if (!label) return;
                    const text = label.textContent.toLowerCase();
                    const li = row.closest('li');
                    if (!text.includes(term) && term) {
                        li.style.display = 'none';
                    } else {
                        li.style.display = '';
                    }
                });
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                if (e.ctrlKey && e.key === 'a') {
                    e.preventDefault();
                    document.getElementById('btn-selall').click();
                } else if (e.key === 'Escape') {
                    document.getElementById('btn-clear').click();
                } else if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    if (!generateBtn.disabled) generateBtn.click();
                } else if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    if (!previewBtn.disabled) previewBtn.click();
                }
            });

            // Preview button
            previewBtn.addEventListener('click', async () => {
                const selected = Array.from(document.querySelectorAll('.file-cb:checked'))
                                      .map(cb => cb.value);
                const prefix = pathPrefixInput.value.trim() || '/';
                try {
                    await showPreview(selected, prefix);
                } catch (err) {
                    showToast(err.message, 'error');
                }
            });

            // Generate button
            generateBtn.addEventListener('click', generateFile);

            // Modal controls
            document.getElementById('modal-close').addEventListener('click', closeModal);
            document.getElementById('modal-close2').addEventListener('click', closeModal);
            document.getElementById('modal-download').addEventListener('click', downloadPreview);
            document.getElementById('modal-generate').addEventListener('click', async () => {
                await generateFile();
            });

            // Save Profile
            saveProfileBtn.addEventListener('click', saveCurrentAsProfile);
        }

        // Start
        loadFullTree();
    })();
</script>
</body>
</html>
`;

// ======================= Node Server ===========================
const server = http.createServer((req, res) => {
    const url = new URL(req.url, `http://localhost:${PORT}`);
    const pathname = url.pathname;

    // Serve main HTML (with script path injected)
    if (req.method === 'GET' && pathname === '/') {
        const scriptPath = fs.realpathSync(__filename); // absolute path of this script
        const injectedHtml = htmlContent.replace('</body>',
            `<script>window.SCRIPT_PATH = ${JSON.stringify(scriptPath)};</script></body>`
        );
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end(injectedHtml);
    }
    // API: full tree (no binary files)
    else if (req.method === 'GET' && pathname === '/api/tree') {
        try {
            const tree = buildDirectoryTree(process.cwd());
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify(tree));
        } catch (err) {
            res.writeHead(500);
            res.end('Error building tree');
        }
    }
    // API: generate preview (Markdown format)
    else if (req.method === 'POST' && pathname === '/api/preview') {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => {
            try {
                const { files, prefix } = JSON.parse(body);
                const preview = buildMarkdownOutput(files, prefix);
                res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
                res.end(preview);
            } catch (e) {
                res.writeHead(500);
                res.end('Preview error');
            }
        });
    }
    // API: generate output file
    else if (req.method === 'POST' && pathname === '/api/generate') {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => {
            try {
                const { files, output, prefix } = JSON.parse(body);
                const outFile = output || outputFile;
                const pPrefix = prefix || pathPrefix;
                const content = buildMarkdownOutput(files, pPrefix);
                fs.writeFileSync(outFile, content, 'utf-8');
                res.writeHead(200);
                res.end('OK');
                console.log(`✅ Generated ${outFile} with ${files.length} files.`);
            } catch (err) {
                console.error(err);
                res.writeHead(500);
                res.end('Server Error');
            }
        });
    }
    else {
        res.writeHead(404);
        res.end('Not found');
    }
});

// ---------- Build Markdown output ----------
function buildMarkdownOutput(selectedFiles, prefix) {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const timestamp = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;

    // Header
    let out = `# Combined Source Code\n\n`;
    out += `**Generated:** ${timestamp}\n\n`;

    // Tree of selected files only
    out += `## Project Tree (selected files)\n\n`;
    out += buildAsciiTree(selectedFiles, prefix) + '\n\n';

    // Individual file sections
    for (const relPath of selectedFiles) {
        const fullPath = path.join(process.cwd(), relPath);
        if (!fs.existsSync(fullPath)) continue;
        // Skip binary just in case (shouldn't happen)
        if (isBinaryFile(fullPath)) continue;
        let content;
        try {
            content = fs.readFileSync(fullPath, 'utf-8');
        } catch (e) {
            content = `<!-- Could not read file: ${relPath} -->`;
        }
        // Strip BOM
        if (content.charCodeAt(0) === 0xfeff) content = content.slice(1);
        const ext = path.extname(relPath).toLowerCase().slice(1) || 'text';
        const safePath = prefix + relPath;
        out += `### ${safePath}\n\n`;
        out += `\`\`\`${ext}\n${content}\n\`\`\`\n\n\n\n`;
    }

    // Remove trailing blank lines but keep exactly one newline at the end
    out = out.replace(/\n{2,}$/, '\n');
    return out;
}

// ---------- ASCII tree builder (for selected files) ----------
function buildAsciiTree(fileList, prefix) {
    if (fileList.length === 0) return '(empty)';
    // Build a map of paths
    const map = {};
    fileList.forEach(p => {
        const parts = p.split('/');
        let node = map;
        for (const part of parts) {
            if (!node[part]) node[part] = {};
            node = node[part];
        }
    });

    function render(node, indent = '', isLast = true, root = true) {
        const keys = Object.keys(node).sort();
        let result = '';
        keys.forEach((key, i) => {
            const isLastChild = i === keys.length - 1;
            const fullPath = root ? key : indent + (isLast ? '    ' : '│   ') + (isLastChild ? '└── ' : '├── ') + key;
            const branch = root ? '' : (isLast ? '    ' : '│   ');
            if (root) {
                result += `└── ${key}\n`;
                result += render(node[key], '', isLastChild, false);
            } else {
                const prefixLine = indent + (isLast ? '    ' : '│   ') + (isLastChild ? '└── ' : '├── ');
                result += prefixLine + key + '\n';
                const nextIndent = indent + (isLast ? '    ' : '│   ');
                result += render(node[key], nextIndent, isLastChild, false);
            }
        });
        return result;
    }

    return '```\n' + (fileList.length > 0 ? '├── ' + fileList[0] : '') + '\n' + render(map, '', true, true) + '```';
}

// ---------- Start server with auto-increment port ----------
function startServer(port) {
    server.listen(port, () => {
        const url = `http://localhost:${port}`;
        console.log(`Scanning directory: ${process.cwd()}`);
        console.log(`Server running at ${url}`);
        console.log(`Press Ctrl+C to stop`);

        const startCmd = process.platform === 'win32' ? 'start' : process.platform === 'darwin' ? 'open' : 'xdg-open';
        exec(`${startCmd} ${url}`);
    });

    server.on('error', (err) => {
        if (err.code === 'EADDRINUSE') {
            console.log(`Port ${port} is in use, trying ${port + 1}...`);
            PORT = port + 1;
            server.close();
            startServer(PORT);
        } else {
            console.error(err);
        }
    });
}

startServer(PORT);