// common helper functions shared across all views

// global cache for filesystem types (populated on DOMContentLoaded)
window.cachedFsTypes = window.cachedFsTypes || [];

// spinner handling – overlay is hidden by default, toggle with show()/hide()
function showSpinner() {
    var o = document.getElementById('spinnerOverlay');
    if (o) o.classList.add('show');
}
function hideSpinner() {
    var o = document.getElementById('spinnerOverlay');
    if (o) o.classList.remove('show');
}

// automatically show spinner on any form submit unless prevented
document.addEventListener('submit', function(e) {
    setTimeout(function() {
        if (!e.defaultPrevented) {
            showSpinner();
        }
    }, 0);
});

// fetch wrapper that handles 401s by redirecting to login
function fetchAuth(input, init) {
    return fetch(input, init).then(function(resp) {
        if (resp.status === 401) {
            window.location = 'login.php';
            return Promise.reject(new Error('unauthorized'));
        }
        return resp;
    });
}

// confirmation dialog utility ------------------------------------------------
function showConfirmation(text, onOk, onCancel) {
    window._confirmActive = true;
    var toHide = [];
    document.querySelectorAll('.modal.show').forEach(function(m) {
        if (m.id === 'confirmModal') return;
        toHide.push(m);
    });
    var remaining = toHide.length;
    var done = function() {
        remaining--;
        if (remaining <= 0) {
            actuallyShowConfirm();
        }
    };
    if (remaining === 0) {
        actuallyShowConfirm();
    } else {
        toHide.forEach(function(m) {
            m.addEventListener('hidden.bs.modal', function handler() {
                m.removeEventListener('hidden.bs.modal', handler);
                done();
            });
            var inst = bootstrap.Modal.getInstance(m);
            if (inst) inst.hide();
        });
    }

    function actuallyShowConfirm() {
        var modal = document.getElementById('confirmModal');
        if (!modal) return;
        var body = modal.querySelector('.modal-body');
        var okBtn = modal.querySelector('.btn-ok');
        var cancelBtn = modal.querySelector('.btn-cancel');
        body.innerHTML = text;
        attachDiskHandlers(body);
        okBtn.onclick = function() {
            if (onOk) {
                try { onOk(); } catch (e) { console.error('error in onOk callback', e); }
            }
            var bs = bootstrap.Modal.getInstance(modal);
            if (bs) bs.hide();
        };
        cancelBtn.style.display = '';
        cancelBtn.onclick = function() {
            if (onCancel) {
                try { onCancel(); } catch (e) { console.error('error in onCancel callback', e); }
            }
            var bs = bootstrap.Modal.getInstance(modal);
            if (bs) bs.hide();
        };
        var bsModal = new bootstrap.Modal(modal);
        setTimeout(function() {
            bsModal.show();
            var highest = 1055;
            document.querySelectorAll('.modal.show').forEach(function(m) {
                if (m === modal) return;
                var z = parseInt(window.getComputedStyle(m).zIndex) || 0;
                if (z >= highest) highest = z + 10;
            });
            modal.style.zIndex = highest;
            var back = document.querySelector('.modal-backdrop:last-of-type');
            if (back) back.style.zIndex = highest - 5;
        }, 10);
        modal.addEventListener('hidden.bs.modal', function() {
            window._confirmActive = false;
        }, {once: true});
    }
}

// simple notification modal ---------------------------------------------------
function showResult(text) {
    var modal = document.getElementById('resultModal');
    if (!modal) return;
    var body = modal.querySelector('.modal-body');
    body.innerHTML = text;
    var highest = 1055;
    document.querySelectorAll('.modal.show').forEach(function(m) {
        var z = parseInt(window.getComputedStyle(m).zIndex) || 0;
        if (z >= highest) highest = z + 10;
    });
    modal.style.zIndex = highest;
    var bs = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
    bs.show();
    modal.addEventListener('shown.bs.modal', function() {
        var back = document.querySelector('.modal-backdrop:last-of-type');
        if (back) back.style.zIndex = highest - 5;
    }, {once: true});
}

// human‑readable size helper (used by snapshot listing)
function hrSize(bytes) {
    var b = parseFloat(bytes.toString().replace(/B$/, ''));
    if (isNaN(b)) return bytes;
    var units = ['B','K','M','G','T','P'];
    var u = 0;
    while (b >= 1024 && u < units.length-1) {
        b /= 1024;
        u++;
    }
    return b.toFixed( (u>0)?1:0 ) + units[u];
}

// modal / disk-related helpers ------------------------------------------------
function attachDiskHandlers(root) {
    root = root || document;
    var deletePartBtn = root.querySelector('#btnDeletePart');
    if (deletePartBtn) {
        deletePartBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Delete the specified partition? This is destructive.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = deletePartBtn.name;
                inp.value = deletePartBtn.value || '';
                deletePartBtn.form.appendChild(inp);
                submitDiskFormAjax(deletePartBtn.form);
            });
        });
    }
    var wipeBtn = root.querySelector('#btnWipe');
    if (wipeBtn) {
        wipeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Wipe the selected disk (GPT table and superblocks)? This cannot be undone.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = wipeBtn.name;
                inp.value = wipeBtn.value || '';
                wipeBtn.form.appendChild(inp);
                submitDiskFormAjax(wipeBtn.form);
            });
        });
    }
    // RAID-specific action buttons inside a raid info modal
    var raidActions = root.querySelectorAll('#btnPartitionRaid, #btnFormatRaid, #btnAddRaid, #btnAddSpareRaid, #btnFailRaid, #btnRebuildRaid, #btnRemoveRaid');
    raidActions.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var action = btn.id.replace('btn','').toLowerCase();
            var raidDev = btn.dataset.raid;
            if (action === 'partitionraid') {
                fetchAuth('views/disks.php?ajax=1&raid=1&disk=' + encodeURIComponent(raidDev))
                    .then(function(resp){ return resp.text(); })
                    .then(function(html){ showInfo(html); })
                    .catch(function(err){ console.error('raid->disk AJAX error', err); });
            } else if (action === 'addraid' || action === 'addspareraid') {
                openAddRaidModal(raidDev, action === 'addspareraid');
            } else if (action === 'failraid') {
                openFailRaidModal(raidDev);
            } else {
                var msgText;
                switch(action) {
                    case 'formatraid':
                        msgText = 'Format the RAID device ' + raidDev + '? All data will be lost.';
                        break;
                    case 'rebuildraid':
                        msgText = 'Initiate rebuild for ' + raidDev + '?';
                        break;
                    case 'removeraid':
                        msgText = 'Stop and remove the RAID array ' + raidDev + '? Data will be lost.';
                        break;
                    default:
                        msgText = 'Proceed with action?';
                }
                showConfirmation(msgText, function() {
                    var f = document.createElement('form');
                    f.method = 'post';
                    f.action = window.location.pathname + window.location.search;
                    f.style.display = 'none';
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'raid_select';
                    inp.value = raidDev;
                    f.appendChild(inp);
                    var act = document.createElement('input');
                    act.type = 'hidden';
                    var phpName = action;
                    if (action === 'removeraid') phpName = 'remove_raid';
                    if (action === 'formatraid') phpName = 'format_raid';
                    if (action === 'rebuildraid') phpName = 'rebuild_raid';
                    act.name = phpName;
                    act.value = '1';
                    f.appendChild(act);
                    document.body.appendChild(f);
                    showSpinner();
                    f.submit();
                });
            }
        });
    });
    // PV-specific action buttons inside info modal
    var pvActions = root.querySelectorAll('#btnCheckPv, #btnRepairPv, #btnMovePv, #btnResizePv, #btnRemovePv');
    pvActions.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var action = btn.id.replace('btn','').toLowerCase();
            var pv = btn.dataset.pv;
            switch(action) {
                case 'checkpv':
                    openSubmodal('checkPvModal', pv);
                    break;
                case 'repairpv':
                    openSubmodal('repairPvModal', pv);
                    break;
                case 'movepv':
                    openSubmodal('movePvModal', pv);
                    break;
                case 'resizepv':
                    openSubmodal('resizePvModal', pv);
                    break;
                case 'removepv':
                    openSubmodal('removePvModal', pv);
                    break;
            }
        });
    });
}

function openAddRaidModal(raidDev, isSpare) {
    var modal = document.getElementById('addRaidModal');
    if (!modal) return;
    modal.dataset.spare = isSpare ? '1' : '0';
    var infoModal = document.getElementById('infoModal');
    if (infoModal) {
        var inst = bootstrap.Modal.getInstance(infoModal);
        if (inst && infoModal.classList.contains('show')) inst.hide();
    }
    var select = modal.querySelector('select[name="new_disk"]');
    select.innerHTML = '<option value="">(loading…)</option>';
    fetchAuth('views/raid.php?json_unused=1')
        .then(function(r){ return r.json(); })
        .then(function(list){
            select.innerHTML = '';
            if (!Array.isArray(list) || list.length === 0) {
                select.innerHTML = '<option value="">(no disks)</option>';
            } else {
                list.forEach(function(d){
                    var o = document.createElement('option');
                    o.value = d; o.textContent = d;
                    select.appendChild(o);
                });
            }
        })
        .catch(function(err){
            console.error('list unused disks error', err);
            select.innerHTML = '<option value="">error</option>';
        });
    modal.querySelector('input[name="raid_select"]').value = raidDev;
    new bootstrap.Modal(modal).show();
}

function openFailRaidModal(raidDev) {
    var modal = document.getElementById('failRaidModal');
    if (!modal) return;
    var infoModal = document.getElementById('infoModal');
    if (infoModal) {
        var inst = bootstrap.Modal.getInstance(infoModal);
        if (inst && infoModal.classList.contains('show')) inst.hide();
    }
    var select = modal.querySelector('select[name="member"]');
    select.innerHTML = '<option value="">(loading…)</option>';
    fetchAuth('views/raid.php?json_members=1&raid=' + encodeURIComponent(raidDev))
        .then(function(r){ return r.json(); })
        .then(function(list){
            select.innerHTML = '';
            if (!Array.isArray(list) || list.length === 0) {
                select.innerHTML = '<option value="">(no members)</option>';
            } else {
                list.forEach(function(m){
                    var o = document.createElement('option');
                    o.value = m; o.textContent = m;
                    select.appendChild(o);
                });
            }
        })
        .catch(function(err){
            console.error('list members error', err);
            select.innerHTML = '<option value="">error</option>';
        });
    modal.querySelector('input[name="raid_select"]').value = raidDev;
    new bootstrap.Modal(modal).show();
}

// used by both disks and raid scripts when info or raid rows are clicked
function showInfo(html) {
    var modal = document.getElementById('infoModal');
    if (!modal) return;
    var body = modal.querySelector('.modal-body');
    body.innerHTML = html;
    attachDiskHandlers(body);
    var btn = body.querySelector('#btnOpenCreate');
    if (btn) btn.addEventListener('click', function() { openSubmodal('createPartModal', btn.dataset.disk); });
    var btn2 = body.querySelector('#btnOpenDelete');
    if (btn2) btn2.addEventListener('click', function() { openSubmodal('deletePartModal', btn2.dataset.disk); });
    var btnFmt = body.querySelector('#btnOpenFormat');
    if (btnFmt) btnFmt.addEventListener('click', function() { openSubmodal('formatPartModal', btnFmt.dataset.disk); });
    body.querySelectorAll('a.raid-member').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var dev = link.dataset.dev || '';
            if (dev) {
                fetchAuth('views/disks.php?ajax=1&raid=1&disk=' + encodeURIComponent(dev))
                    .then(function(resp){ return resp.text(); })
                    .then(function(html){ showInfo(html); })
                    .catch(function(err){ console.error('member AJAX error', err); });
            }
        });
    });
    var actions = body.querySelector('.action-buttons');
    if (actions) {
        var footer = modal.querySelector('.modal-footer');
        footer.querySelectorAll('button:not([data-bs-dismiss])').forEach(function(b) { b.remove(); });
        var closeBtn = footer.querySelector('[data-bs-dismiss]');
        if (closeBtn) {
            footer.insertBefore(actions, closeBtn);
        } else {
            footer.appendChild(actions);
        }
    }
    modal.querySelectorAll('form').forEach(function(form) {
        form.querySelectorAll('button').forEach(function(btn) {
            try {
                if (btn.type && btn.type.toLowerCase() === 'submit') {
                    btn.addEventListener('click', function() {
                        form._lastSubmitName = btn.name;
                        form._lastSubmitValue = btn.value || '';
                    });
                }
            } catch (e) {}
        });
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitDiskFormAjax(form);
        });
    });
    var bsModal = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
    if (!modal.classList.contains('show')) {
        bsModal.show();
    }
}

function openSubmodal(subId, disk) {
    var info = document.getElementById('infoModal');
    var infoInst = bootstrap.Modal.getInstance(info);
    var doShow = function() {
        var sub = document.getElementById(subId);
        if (!sub) return;
        var hid = sub.querySelector('input[name=disk]') || sub.querySelector('input[name=pv]');
        if (hid) hid.value = disk;
        if (disk.startsWith('/dev/md')) {
            var raidInp = sub.querySelector('input[name=raid]');
            if (!raidInp) {
                raidInp = document.createElement('input');
                raidInp.type = 'hidden';
                raidInp.name = 'raid';
                raidInp.value = '1';
                var form = sub.querySelector('form');
                if (form) form.appendChild(raidInp);
                else sub.appendChild(raidInp);
            } else {
                raidInp.value = '1';
            }
        }
        // clear non-hidden input/select fields (but not the hidden pv/disk)
        sub.querySelectorAll('input:not([type=hidden]), select').forEach(function(i){ i.value = ''; });
        if (subId === 'createPartModal' && disk.startsWith('/dev/md')) {
            if (!sub.querySelector('.raid-note')) {
                var note = document.createElement('div');
                note.className = 'alert alert-warning raid-note';
                note.textContent = 'RAID devices often report "unrecognised disk label" and may not display partitions here; the UI will show a success/error message and may fall back to sgdisk if parted fails.';
                var body = sub.querySelector('.modal-body');
                if (body) body.insertBefore(note, body.firstChild);
            }
        }
        if (subId === 'deletePartModal' || subId === 'formatPartModal') {
            var sel = sub.querySelector('select[name="part_num"]');
            if (sel) {
                sel.innerHTML = '<option value="">Loading…</option>';
                fetchAuth('views/disks.php?list_parts=1&disk=' + encodeURIComponent(disk))
                    .then(function(r){ return r.json(); })
                    .then(function(list){
                        sel.innerHTML = '';
                        if (!Array.isArray(list) || list.length === 0) {
                            sel.innerHTML = '<option value="">(no partitions)</option>';
                        } else {
                            list.forEach(function(p){
                                var o = document.createElement('option');
                                o.value = p.num;
                                o.textContent = p.num + ': ' + p.label;
                                sel.appendChild(o);
                            });
                        }
                    })
                    .catch(function(err){
                        console.error('partition list error', err);
                        sel.innerHTML = '<option value="">error</option>';
                    });
            }
        }
        if (subId === 'formatPartModal') {
            var fsel = sub.querySelector('select[name="fstype"]');
            if (fsel) {
                if (fsel.options.length <= 1 && window.cachedFsTypes && window.cachedFsTypes.length) {
                    fsel.innerHTML = '';
                    window.cachedFsTypes.forEach(function(fs) {
                        var o = document.createElement('option');
                        o.value = fs; o.textContent = fs;
                        fsel.appendChild(o);
                    });
                }
                if ([].slice.call(fsel.options).some(o=>o.value==='ext4')) {
                    fsel.value = 'ext4';
                }
            }
        }
        attachDiskHandlers(sub);
        sub.querySelectorAll('form').forEach(function(form) {
            if (form._ajaxBound) return;
            form._ajaxBound = true;
            form.querySelectorAll('button[type="submit"]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    form._lastSubmitName = btn.name;
                    form._lastSubmitValue = btn.value || '';
                });
            });
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                ['formatPartModal','createPartModal','deletePartModal'].forEach(function(id){
                    var m = document.getElementById(id);
                    if (m) {
                        var inst = bootstrap.Modal.getInstance(m);
                        if (inst && m.classList.contains('show')) inst.hide();
                    }
                });
                var btn = e.submitter || null;
                var action = btn && btn.name ? btn.name : '';
                form._lastSubmitName = action;
                var confirmMsg = null;
                switch(action) {
                    case 'format_part':
                        confirmMsg = 'Format the selected partition? This will destroy all data on it.';
                        break;
                    case 'create_part_size':
                    case 'create_part':
                        confirmMsg = 'Create this partition? This may overwrite existing data.';
                        break;
                    case 'delete_part':
                        confirmMsg = 'Delete the selected partition? This is destructive.';
                        break;
                }
                if (confirmMsg) {
                    showConfirmation(confirmMsg, function() {
                        submitDiskFormAjax(form);
                    });
                } else {
                    submitDiskFormAjax(form);
                }
            });
        });
        var bs = new bootstrap.Modal(sub);
        bs.show();
    };
    if (infoInst && info.classList.contains('show')) {
        info.addEventListener('hidden.bs.modal', function handler() {
            info.removeEventListener('hidden.bs.modal', handler);
            doShow();
        });
        infoInst.hide();
    } else {
        doShow();
    }
}

// global dark mode helpers and initial message --------------------------------
function swapBgClasses(enable) {
    if (enable) {
        document.body.classList.remove('bg-light', 'bg-dark');
        document.body.classList.add('bg-main');
    } else {
        document.body.classList.remove('bg-main', 'bg-dark');
        document.body.classList.add('bg-light');
    }
}

function initDarkMode() {
    var darkToggle = document.getElementById('darkModeToggle');
    function applyStoredDarkMode() {
        var val = localStorage.getItem('darkMode');
        var enabled = val === '1';
        if (enabled) document.body.classList.add('dark-mode');
        else document.body.classList.remove('dark-mode');
        swapBgClasses(enabled);
        if (darkToggle) darkToggle.checked = enabled;
    }
    if (darkToggle) {
        darkToggle.addEventListener('change', function() {
            var enabled = !!darkToggle.checked;
            if (enabled) document.body.classList.add('dark-mode');
            else document.body.classList.remove('dark-mode');
            swapBgClasses(enabled);
            localStorage.setItem('darkMode', enabled ? '1' : '0');
        });
    }
    applyStoredDarkMode();
}

function handleInitialMessage() {
    var msgEl = document.getElementById('initialMessage');
    if (msgEl) {
        var text = msgEl.innerHTML;
        var reopenVg = msgEl.dataset.reopenVg === '1';
        var reopenLv = msgEl.dataset.reopenLv === '1';
        var reload = msgEl.dataset.reload === '1';
        if (text) {
            showConfirmation(text, function() {
                if (reopenVg) {
                    new bootstrap.Modal(document.getElementById('vgModal')).show();
                }
                if (reopenLv) {
                    new bootstrap.Modal(document.getElementById('lvModal')).show();
                }
                if (reload) {
                    window.location.reload();
                }
            });
        } else {
            if (reopenVg) {
                new bootstrap.Modal(document.getElementById('vgModal')).show();
            }
            if (reopenLv) {
                new bootstrap.Modal(document.getElementById('lvModal')).show();
            }
            if (reload) {
                window.location.reload();
            }
        }
        msgEl.parentNode.removeChild(msgEl);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initDarkMode();
    handleInitialMessage();
    // cache filesystem types if present
    var fsel = document.querySelector('select[name="fstype"]');
    if (fsel) {
        window.cachedFsTypes = [].slice.call(fsel.options)
            .map(function(o){ return o.value; })
            .filter(function(v){ return v; });
        console.log('cached filesystem types', window.cachedFsTypes);
    }
});

// cleanup orphaned backdrops --------------------------------------------------
document.addEventListener('hidden.bs.modal', function() {
    setTimeout(function() {
        if (document.querySelectorAll('.modal.show').length === 0) {
            document.querySelectorAll('.modal-backdrop').forEach(function(b){ b.remove(); });
        }
    }, 10);
});
