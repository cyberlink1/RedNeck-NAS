// General JS helpers
console.log('App script loaded');

// cache of filesystem types read from the initial page; used as a fallback
// if a modal loses its options after an AJAX refresh of the info modal.
var cachedFsTypes = [];
document.addEventListener('DOMContentLoaded', function() {
    var fsel = document.querySelector('select[name="fstype"]');
    if (fsel) {
        cachedFsTypes = [].slice.call(fsel.options)
            .map(function(o){ return o.value; })
            .filter(function(v){ return v; });
        console.log('cached filesystem types', cachedFsTypes);
    }

    var addForm = document.getElementById('addRaidForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var isSpare = addForm.closest('.modal')?.dataset?.spare === '1';
            var msg = isSpare ? 'Add selected disk as spare?' : 'Add selected disk to RAID?  This will start a rebuild.';
            showConfirmation(msg, function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = isSpare ? 'add_spare' : 'add_member';
                inp.value = '1';
                addForm.appendChild(inp);
                addForm.submit();
            });
        });
    }
    var failForm = document.getElementById('failRaidForm');
    if (failForm) {
        failForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Mark the selected member as failed and remove it?', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'fail_member';
                inp.value = '1';
                failForm.appendChild(inp);
                failForm.submit();
            });
        });
    }
});

// helper used by both the modal submit interceptor and the
// confirmation callbacks; posts the form by AJAX and refreshes the info
// modal contents with whatever HTML the server returns.
// we also ensure the disk field is sent and provide a warning if the
// response is empty (a missing disk value is the usual culprit).
function submitDiskFormAjax(form) {
    console.log('submitDiskFormAjax invoked, form=', form, 'lastSubmitName=', form._lastSubmitName);
    var data = new FormData(form);
    // ensure disk field is always sent (some browsers drop empty hidden inputs)
    var diskInput = form.querySelector('input[name="disk"]');
    if (diskInput && diskInput.value) {
        data.set('disk', diskInput.value);
    }
    if (form._lastSubmitName) {
        data.append(form._lastSubmitName, form._lastSubmitValue);
    }
    data.append('ajax', '1');
    fetch('views/disks.php', { method: 'POST', body: data })
        .then(function(resp) { return resp.text(); })
        .then(function(newHtml) {
            if (newHtml.trim() === '') {
                // nothing returned – most likely the disk value was missing
                showResult('Error: no response from server (disk may be unset)');
                return;
            }
            // hide any submodal that might still be open
            ['createPartModal','deletePartModal','formatPartModal'].forEach(function(id) {
                var m = document.getElementById(id);
                var inst = bootstrap.Modal.getInstance(m);
                if (inst && m.classList.contains('show')) inst.hide();
            });

            // parse the returned HTML so we can inspect/strip special markers
            var temp = document.createElement('div');
            temp.innerHTML = newHtml;
            var genericMsg = null;
            // capture any bootstrap info alert text
            var alertElt = temp.querySelector('.alert.alert-info');
            if (alertElt) {
                genericMsg = alertElt.textContent.trim();
                alertElt.remove();
            }
            var res = temp.querySelector('#formatResult');
            var fmtOk, fmtMsg;
            if (res) {
                fmtOk = res.dataset.ok === '1';
                fmtMsg = res.dataset.msg || (fmtOk ? 'Format completed.' : 'Format failed.');
                res.remove();
            }
            newHtml = temp.innerHTML;

            // update the preview first
            showInfo(newHtml);

            // then, if we had any result message, show it in the result modal
            if (typeof fmtOk !== 'undefined' || genericMsg) {
                var infoModal = document.getElementById('infoModal');
                var doShow = function() { showResult(genericMsg || fmtMsg); };
                if (infoModal && infoModal.classList.contains('show')) {
                    doShow();
                } else if (infoModal) {
                    var handler = function() {
                        infoModal.removeEventListener('shown.bs.modal', handler);
                        doShow();
                    };
                    infoModal.addEventListener('shown.bs.modal', handler);
                } else {
                    doShow();
                }
            }
        })
        .catch(function(err) {
            console.error('modal form ajax error', err);
        });
}

function openAddRaidModal(raidDev, isSpare) {
    var modal = document.getElementById('addRaidModal');
    if (!modal) return;
    modal.dataset.spare = isSpare ? '1' : '0';
    // hide any underlying info/details modal so our dialog is on top
    var infoModal = document.getElementById('infoModal');
    if (infoModal) {
        var inst = bootstrap.Modal.getInstance(infoModal);
        if (inst && infoModal.classList.contains('show')) inst.hide();
    }
    var select = modal.querySelector('select[name="new_disk"]');
    select.innerHTML = '<option value="">(loading…)</option>';
    fetch('views/raid.php?json_unused=1')
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
    fetch('views/raid.php?json_members=1&raid=' + encodeURIComponent(raidDev))
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
    // format button handler similar to wipe
    var formatBtn = root.querySelector('#btnOpenFormat');
    if (formatBtn) {
        // no direct action here; formatting happens in submodal form
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

    // RAID-specific action buttons inside a raid info modal
    var raidActions = root.querySelectorAll('#btnPartitionRaid, #btnFormatRaid, #btnAddRaid, #btnAddSpareRaid, #btnFailRaid, #btnRebuildRaid, #btnRemoveRaid');
    raidActions.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var action = btn.id.replace('btn','').toLowerCase();
            var raidDev = btn.dataset.raid;
            console.log('raid action click', action, raidDev);
            if (action === 'partitionraid') {
                // immediately switch to disk view without extra confirmation
                fetch('views/disks.php?ajax=1&raid=1&disk=' + encodeURIComponent(raidDev))
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
                    f.style.display = 'none';
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'raid_select';
                    inp.value = raidDev;
                    f.appendChild(inp);
                    var act = document.createElement('input');
                    act.type = 'hidden';
                    // PHP expects underscore names for some actions
                    var phpName = action;
                    if (action === 'removeraid') phpName = 'remove_raid';
                    if (action === 'formatraid') phpName = 'format_raid';
                    if (action === 'rebuildraid') phpName = 'rebuild_raid';
                    act.name = phpName;
                    act.value = '1';
                    f.appendChild(act);
                    document.body.appendChild(f);
                    f.submit();
                });
            }
        });
    });
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
    var btnFmt = body.querySelector('#btnOpenFormat');
    if (btnFmt) btnFmt.addEventListener('click', function() { openSubmodal('formatPartModal', btnFmt.dataset.disk); });
    // raid-member links for partitioning underlying disks
    body.querySelectorAll('a.raid-member').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var dev = link.dataset.dev || '';
            if (dev) {
                fetch('views/disks.php?ajax=1&raid=1&disk=' + encodeURIComponent(dev))
                    .then(function(resp){ return resp.text(); })
                    .then(function(html){ showInfo(html); })
                    .catch(function(err){ console.error('member AJAX error', err); });
            }
        });
    });

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
        // if this is an md device being edited from RAID view, make sure the
        // hidden raid flag is present so subsequent refreshes don't disable
        // the buttons.
        if (disk.startsWith('/dev/md')) {
            var raidInp = sub.querySelector('input[name=raid]');
            if (!raidInp) {
                raidInp = document.createElement('input');
                raidInp.type = 'hidden';
                raidInp.name = 'raid';
                raidInp.value = '1';
                // put inside first form if present
                var form = sub.querySelector('form');
                if (form) form.appendChild(raidInp);
                else sub.appendChild(raidInp);
            } else {
                raidInp.value = '1';
            }
        }
        // clear other inputs (size/partnum) to avoid leftover values
        sub.querySelectorAll('input[name="size"], select[name="part_num"]').forEach(function(i){ i.value = ''; });
        // warn when opening the partition creation dialog on an md device
        if (subId === 'createPartModal' && disk.startsWith('/dev/md')) {
            if (!sub.querySelector('.raid-note')) {
                var note = document.createElement('div');
                note.className = 'alert alert-warning raid-note';
                note.textContent = 'RAID devices often report "unrecognised disk label" and may not display partitions here; the UI will show a success/error message and may fall back to sgdisk if parted fails.';
                var body = sub.querySelector('.modal-body');
                if (body) body.insertBefore(note, body.firstChild);
            }
        }
        // if this is delete or format modal, populate the partition dropdown
        if (subId === 'deletePartModal' || subId === 'formatPartModal') {
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
        // if format modal, default filesystem to ext4 if available
        if (subId === 'formatPartModal') {
            var fsel = sub.querySelector('select[name="fstype"]');
            if (fsel) {
                // if the select lost its options (possible after a reload) use the
                // cached list we gathered on page load
                if (fsel.options.length <= 1 && cachedFsTypes.length) {
                    fsel.innerHTML = '';
                    cachedFsTypes.forEach(function(fs) {
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
                // hide any open submodal; the confirmation will appear above all
                ['formatPartModal','createPartModal','deletePartModal'].forEach(function(id){
                    var m = document.getElementById(id);
                    if (m) {
                        var inst = bootstrap.Modal.getInstance(m);
                        if (inst && m.classList.contains('show')) inst.hide();
                    }
                });

                // figure out which button triggered the submit (modern browsers)
                var btn = e.submitter || null;
                var action = btn && btn.name ? btn.name : '';
                // store for backwards compatibility
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

function showConfirmation(text, onOk, onCancel) {
    console.log('showConfirmation called with text=', text);
    // hide any visible info or create‑raid modals before showing the
    // confirmation so its backdrop is on top.  once *all* requested
    // modals have finished hiding we call actuallyShowConfirm (or call it
    // immediately if none were visible).
    var toHide = [];
    var info = document.getElementById('infoModal');
    if (info) {
        var iModal = bootstrap.Modal.getInstance(info);
        if (iModal && info.classList.contains('show')) {
            toHide.push(info);
        }
    }
    var create = document.getElementById('createRaidModal');
    if (create) {
        var cModal = bootstrap.Modal.getInstance(create);
        if (cModal && create.classList.contains('show')) {
            toHide.push(create);
        }
    }
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
        if (onCancel) {
            cancelBtn.style.display = '';
            cancelBtn.onclick = function() { onCancel(); var bs = bootstrap.Modal.getInstance(modal); bs.hide(); };
        } else {
            cancelBtn.style.display = 'none';
        }
        var bsModal = new bootstrap.Modal(modal);
        // show after a tiny delay so any existing backdrop from a just-closed
        // modal has been removed; this prevents the new dialog from ending up
        // visually beneath the old backdrop.
        setTimeout(function() { bsModal.show(); }, 10);
    }
}

// helper for simple notification modal (does not close other modals)
function showResult(text) {
    var modal = document.getElementById('resultModal');
    if (!modal) return;
    var body = modal.querySelector('.modal-body');
    body.innerHTML = text;
    // compute a z-index higher than any currently shown modal
    var highest = 1055;
    document.querySelectorAll('.modal.show').forEach(function(m) {
        var z = parseInt(window.getComputedStyle(m).zIndex) || 0;
        if (z >= highest) highest = z + 10;
    });
    modal.style.zIndex = highest;
    var bs = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
    bs.show();
    // also bump the backdrop after it appears
    modal.addEventListener('shown.bs.modal', function() {
        var back = document.querySelector('.modal-backdrop:last-of-type');
        if (back) back.style.zIndex = highest - 5;
    }, {once: true});
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
    // support any remove button generated per row or the old single button
    var raidRemoveButtons = document.querySelectorAll('button[name="remove_raid"]');
    raidRemoveButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Stop and remove selected RAID array? Data on array will be lost.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = btn.name;
                inp.value = btn.value || '';
                btn.form.appendChild(inp);
                btn.form.submit();
            });
        });
    });

    // Create RAID button uses data-bs attributes; no additional JS needed here


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

// raid table row info/selection (similar pattern)
(function() {
    var raidTable = document.getElementById('raidTable');
    if (raidTable) {
        raidTable.querySelectorAll('tbody tr').forEach(function(row) {
            row.addEventListener('click', function() {
                var dev = row.dataset.dev || '';
                fetch('views/raid.php?ajax=1&raid=' + encodeURIComponent(dev))
                    .then(function(resp) { return resp.text(); })
                    .then(function(html) { showInfo(html); })
                    .catch(function(err) { console.error('raid AJAX error', err); });
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
