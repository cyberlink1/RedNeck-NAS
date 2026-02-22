// General JS helpers
console.log('App script loaded');

// helper used by both the modal submit interceptor and the
// confirmation callbacks; posts the form by AJAX and refreshes the info
// modal contents with whatever HTML the server returns.
function submitDiskFormAjax(form) {
    var data = new FormData(form);
    if (form._lastSubmitName) {
        data.append(form._lastSubmitName, form._lastSubmitValue);
    }
    data.append('ajax', '1');
    fetch('views/disks.php', { method: 'POST', body: data })
        .then(function(resp) { return resp.text(); })
        .then(function(newHtml) {
            showInfo(newHtml);
        })
        .catch(function(err) {
            console.error('modal form ajax error', err);
        });
}

function attachDiskHandlers(root) {
    root = root || document;
    var deletePartBtn = root.querySelector('#btnDeletePart');
    if (deletePartBtn) {
        deletePartBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Delete the specified partition? This is destructive.', function() {
                // add the button parameter then submit via AJAX so the
                // page underneath isn’t replaced
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
                // include the button name/value so the server sees wipe_disk
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = wipeBtn.name;
                inp.value = wipeBtn.value || '';
                wipeBtn.form.appendChild(inp);
                submitDiskFormAjax(wipeBtn.form);
            });
        });
    }
}

function showInfo(html) {
    var modal = document.getElementById('infoModal');
    if (!modal) return;
    var body = modal.querySelector('.modal-body');
    body.innerHTML = html;
    // attach handlers for any form buttons inside (delete/wipe requires confirm)
    attachDiskHandlers(body);

    // wire up the open‑modal buttons as well
    var btn = body.querySelector('#btnOpenCreate');
    if (btn) btn.addEventListener('click', function() { openSubmodal('createPartModal', btn.dataset.disk); });
    var btn2 = body.querySelector('#btnOpenDelete');
    if (btn2) btn2.addEventListener('click', function() { openSubmodal('deletePartModal', btn2.dataset.disk); });

    // move any action buttons/forms into the modal footer so they're aligned
    // with the Close button
    var actions = body.querySelector('.action-buttons');
    if (actions) {
        var footer = modal.querySelector('.modal-footer');
        // remove existing non-close buttons in footer
        footer.querySelectorAll('button:not([data-bs-dismiss])').forEach(function(b) { b.remove(); });
        // insert actions *before* the close button so close stays rightmost
        var closeBtn = footer.querySelector('[data-bs-dismiss]');
        if (closeBtn) {
            footer.insertBefore(actions, closeBtn);
        } else {
            footer.appendChild(actions);
        }
    }

    // convert any forms in the modal (body or footer) into AJAX submissions so
    // the surrounding page isn’t replaced with card HTML; after a POST we
    // simply refresh the modal contents with the updated response.
    modal.querySelectorAll('form').forEach(function(form) {
        // remember which submit button was clicked so its name/value can be
        // included (FormData doesn’t automatically include the button unless
        // it’s passed to the constructor).  this fixes the “create partition”
        // action not being seen on AJAX submits.
        form.querySelectorAll('button[type=submit]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                form._lastSubmitName = btn.name;
                form._lastSubmitValue = btn.value || '';
            });
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitDiskFormAjax(form);
        });
    });

    // reuse existing bootstrap.Modal instance to avoid stacking backdrops when
    // the modal is already visible.
    var bsModal = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
    if (!modal.classList.contains('show')) {
        bsModal.show();
    }
}

// open a secondary partition modal; hide infoModal first so backdrop stays
// sensible and we can restore the preview when the submodal closes.
function openSubmodal(subId, disk) {
    var info = document.getElementById('infoModal');
    var infoInst = bootstrap.Modal.getInstance(info);
    var doShow = function() {
        var sub = document.getElementById(subId);
        if (!sub) return;
        // populate disk field
        var hid = sub.querySelector('input[name=disk]');
        if (hid) hid.value = disk;
        // clear other inputs (size/partnum) to avoid leftover values
        sub.querySelectorAll('input[name="size"], select[name="part_num"]').forEach(function(i){ i.value = ''; });
        // if this is the delete modal, populate the select via AJAX
        if (subId === 'deletePartModal') {
            var sel = sub.querySelector('select[name="part_num"]');
            if (sel) {
                sel.innerHTML = '<option value="">Loading…</option>';
                fetch('views/disks.php?list_parts=1&disk=' + encodeURIComponent(disk))
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
        // attach handlers inside the submodal as well (delete confirmation)
        attachDiskHandlers(sub);
        // intercept forms for AJAX submission just like showInfo does
        sub.querySelectorAll('form').forEach(function(form) {
            // avoid binding twice if modal opened multiple times
            if (form._ajaxBound) return;
            form._ajaxBound = true;
            form.querySelectorAll('button[type=submit]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    form._lastSubmitName = btn.name;
                    form._lastSubmitValue = btn.value || '';
                });
            });
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                submitDiskFormAjax(form);
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

// helper used by both the modal submit interceptor and the
// confirmation callbacks; posts the form by AJAX and refreshes the info
// modal contents with whatever HTML the server returns.
function submitDiskFormAjax(form) {
    var data = new FormData(form);
    if (form._lastSubmitName) {
        data.append(form._lastSubmitName, form._lastSubmitValue);
    }
    data.append('ajax', '1');
    fetch('views/disks.php', { method: 'POST', body: data })
        .then(function(resp) { return resp.text(); })
        .then(function(newHtml) {
            // hide any submodal that might still be open
            ['createPartModal','deletePartModal'].forEach(function(id) {
                var m = document.getElementById(id);
                var inst = bootstrap.Modal.getInstance(m);
                if (inst && m.classList.contains('show')) inst.hide();
            });
            showInfo(newHtml);
        })
        .catch(function(err) {
            console.error('modal form ajax error', err);
        });
}

function showConfirmation(text, onOk, onCancel) {
    // if info modal is open and currently shown, hide it first and only
    // display the confirmation once the preview has completely closed.  this
    // avoids the common problem of the confirm dialog appearing behind the
    // still‑visible info modal/backdrop when actions are triggered from the
    // preview window (e.g. wipe/delete buttons).
    var info = document.getElementById('infoModal');
    if (info) {
        var iModal = bootstrap.Modal.getInstance(info);
        if (iModal && info.classList.contains('show')) {
            var handler = function() {
                info.removeEventListener('hidden.bs.modal', handler);
                actuallyShowConfirm();
            };
            info.addEventListener('hidden.bs.modal', handler);
            iModal.hide();
            return;
        }
    }
    actuallyShowConfirm();

    function actuallyShowConfirm() {
        var modal = document.getElementById('confirmModal');
        if (!modal) return;
        var body = modal.querySelector('.modal-body');
        var okBtn = modal.querySelector('.btn-ok');
        var cancelBtn = modal.querySelector('.btn-cancel');
        // allow simple HTML (e.g. <br>) in messages
        body.innerHTML = text;
        // attach handlers inside modal content if any
        attachDiskHandlers(body);
        okBtn.onclick = function() {
            var bs = bootstrap.Modal.getInstance(modal);
            bs.hide();
            if (onOk) {
                // delay slightly to ensure hide animation starts
                setTimeout(onOk, 10);
            }
        };
        cancelBtn.onclick = function() { if (onCancel) onCancel(); var bs = bootstrap.Modal.getInstance(modal); bs.hide(); };
        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
}

// convert comma or space separated device string into hidden inputs before submit
window.addEventListener('DOMContentLoaded', function() {
    console.log('DOMContentLoaded handler fired');
    var form = document.getElementById('raidForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            var devicesInput = document.getElementById('raidDevices');
        if (devicesInput) {
            // remove existing hidden fields
            var container = document.getElementById('deviceFields');
            container.innerHTML = '';
            var text = devicesInput.value.trim();
            if (text) {
                var parts = text.split(/[,\s]+/);
                parts.forEach(function(dev) {
                    if (dev) {
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'devices[]';
                        inp.value = dev;
                        container.appendChild(inp);
                    }
                });
            }
        }
    });

    // attach confirmation dialogs to action buttons
    var removeLvBtn = document.getElementById('btnRemoveLv');
    if (removeLvBtn) {
        removeLvBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Remove selected LV? This will destroy its data.', function() {
                // ensure the button name/value is included since form.submit() omits it
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = removeLvBtn.name;
                inp.value = removeLvBtn.value || '';
                removeLvBtn.form.appendChild(inp);
                removeLvBtn.form.submit();
            });
        });
    }
    var removeVgBtn = document.getElementById('btnRemoveVg');
    if (removeVgBtn) {
        removeVgBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Remove selected VG? All contained LVs will be lost.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = removeVgBtn.name;
                inp.value = removeVgBtn.value || '';
                removeVgBtn.form.appendChild(inp);
                removeVgBtn.form.submit();
            });
        });
    }
    var formatLvBtn = document.getElementById('btnFormatLv');
    if (formatLvBtn) {
        formatLvBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Format selected LV? All data will be erased.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = formatLvBtn.name;
                inp.value = formatLvBtn.value || '';
                formatLvBtn.form.appendChild(inp);
                formatLvBtn.form.submit();
            });
        });
    }
    var removeRaidBtn = document.getElementById('btnRemoveRaid');
    if (removeRaidBtn) {
        removeRaidBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Stop and remove selected RAID array? Data on array will be lost.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = removeRaidBtn.name;
                inp.value = removeRaidBtn.value || '';
                removeRaidBtn.form.appendChild(inp);
                removeRaidBtn.form.submit();
            });
        });
    }


    // mount/unmount confirmation on mounts.php
    var mountBtn = document.getElementById('btnMount');
    if (mountBtn) {
        mountBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Mount the selected logical volume?\nThis will create or use /export/<em>subdir</em>.', function() {
                mountBtn.form.submit();
            });
        });
    }
    var umountBtn = document.getElementById('btnUmount');
    if (umountBtn) {
        umountBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Unmount the selected export?\nAny users accessing it will be disconnected.', function() {
                umountBtn.form.submit();
            });
        });
    }

    // NFS export removal confirmation (buttons generated per row)
    var exportButtons = document.querySelectorAll('button[name="remove_export"]');
    exportButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Remove this NFS export?', function() {
                // button name/value already in form
                btn.form.submit();
            });
        });
    });
    // end if(form) block
    }
});

// disk table row info/selection (run regardless of DOMContentLoaded state)
(function() {
    var diskTable = document.getElementById('diskTable');
    console.log('diskTable element', diskTable);
    if (diskTable) {
        diskTable.querySelectorAll('tbody tr').forEach(function(row) {
            row.addEventListener('click', function() {
                var dev = row.dataset.dev || '';
                console.log('disk row clicked', dev);
                // request card HTML via AJAX
                fetch('views/disks.php?ajax=1&disk=' + encodeURIComponent(dev))
                    .then(function(resp) { return resp.text(); })
                    .then(function(html) {
                        showInfo(html);
                    })
                    .catch(function(err) {
                        console.error('AJAX error', err);
                    });
            });
        });
    }
})();

// display any message that was provided by PHP via a hidden element
window.addEventListener('DOMContentLoaded', function() {
    var msgEl = document.getElementById('initialMessage');
    if (msgEl) {
        var text = msgEl.innerHTML;
        if (text) {
            showConfirmation(text);
        }
        msgEl.parentNode.removeChild(msgEl);
    }
});
