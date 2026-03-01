// NFS exports view specific behaviors

document.addEventListener('DOMContentLoaded', function() {
    // confirm removal buttons generated per row
    document.querySelectorAll('.btn-remove-export').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var form = btn.closest('form');
            showConfirmation('Remove this export entry? This will update ' + (window.CONFIG && window.CONFIG.exportsFile ? window.CONFIG.exportsFile : '/etc/exports') + '.', function() {
                form.submit();
            });
        });
    });

    // create-export modal preparation
    function addClientRow(client, opts) {
        var tbody = document.querySelector('#clientTable tbody');
        if (!tbody) return;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td class="client-cell">'+(client||'')+'</td>' +
                       '<td class="opts-cell">'+(opts||'')+'</td>' +
                       '<td>' +
                         '<button type="button" class="btn btn-sm btn-secondary btn-edit-client">&#9998;</button> ' +
                         '<button type="button" class="btn btn-sm btn-danger btn-del-client">&times;</button>' +
                       '</td>';
        tbody.appendChild(tr);
        tr.querySelector('.btn-del-client').addEventListener('click', function() {
            tr.remove();
        });
        tr.querySelector('.btn-edit-client').addEventListener('click', function() {
            showClientModal(function(c,o){
                tr.querySelector('.client-cell').textContent = c;
                tr.querySelector('.opts-cell').textContent = o;
            }, client, opts);
        });
    }

    function showClientModal(onSave, initialClient, initialOpts) {
        var clientModal = document.getElementById('clientEntryModal');
        if (!clientModal) return;
        var addr = document.getElementById('clientAddr');
        addr.value = initialClient || '';
        var rwsel = document.getElementById('opt_rw_ro');
        if (rwsel) rwsel.value = '';
        ['opt_squash','opt_sync','opt_subtree','opt_wdelay'].forEach(function(id){
            var s = document.getElementById(id);
            if (s) s.value = '';
        });
        ['noaccess','crossmnt','nohide'].forEach(function(o){
            var chk = document.getElementById('opt_' + o);
            if (chk) chk.checked = false;
        });
        ['anonuid','anongid','fsid'].forEach(function(o){
            var inp = document.getElementById('opt_' + o);
            if (inp) inp.value = '';
        });
        var optsToApply = initialOpts || '';
        if (!optsToApply) {
            optsToApply = 'rw,root_squash,no_subtree_check';
        }
        var parts = optsToApply.split(',');
        parts.forEach(function(p) {
            if (!p) return;
            if (p === 'rw' || p === 'ro') {
                if (rwsel) rwsel.value = p;
            } else if (['root_squash','no_root_squash','all_squash'].includes(p)) {
                var sel = document.getElementById('opt_squash');
                if (sel) sel.value = p;
            } else if (['sync','async'].includes(p)) {
                var sel = document.getElementById('opt_sync');
                if (sel) sel.value = p;
            } else if (['subtree_check','no_subtree_check'].includes(p)) {
                var sel = document.getElementById('opt_subtree');
                if (sel) sel.value = p;
            } else if (['wdelay','no_wdelay'].includes(p)) {
                var sel = document.getElementById('opt_wdelay');
                if (sel) sel.value = p;
            } else if (['noaccess','crossmnt','nohide'].includes(p)) {
                var chk = document.getElementById('opt_' + p);
                if (chk) chk.checked = true;
            } else if (p.indexOf('=') !== -1) {
                var kv = p.split('=');
                var el = document.getElementById('opt_' + kv[0]);
                if (el) el.value = kv[1];
            }
        });
        var form = document.getElementById('clientForm');
        var handler = function(e) {
            e.preventDefault();
            var c = addr.value.trim();
            var optsArr = [];
            var rwsel = document.getElementById('opt_rw_ro');
            if (rwsel && rwsel.value) optsArr.push(rwsel.value);
            ['opt_squash','opt_sync','opt_subtree','opt_wdelay'].forEach(function(id){
                var sel = document.getElementById(id);
                if (sel && sel.value) optsArr.push(sel.value);
            });
            ['noaccess','crossmnt','nohide'].forEach(function(o){
                var chk = document.getElementById('opt_' + o);
                if (chk && chk.checked) optsArr.push(o);
            });
            ['anonuid','anongid','fsid'].forEach(function(o){
                var inp = document.getElementById('opt_' + o);
                if (inp && inp.value.trim() !== '') {
                    optsArr.push(o + '=' + inp.value.trim());
                }
            });
            var o = optsArr.join(',');
            if (c) {
                onSave(c, o);
            }
            var bs = bootstrap.Modal.getInstance(clientModal);
            if (bs) bs.hide();
        };
        form.addEventListener('submit', handler, {once:true});
        new bootstrap.Modal(clientModal).show();
    }

    var createExportModal = document.getElementById('createExportModal');
    if (createExportModal) {
        createExportModal.addEventListener('show.bs.modal', function() {
            var form = document.getElementById('createExportForm');
            if (!form) return;
            form.reset();
            var tbody = document.querySelector('#clientTable tbody');
            if (tbody) tbody.innerHTML = '';
            addClientRow();
        });
        var addBtn = document.getElementById('addClientBtn');
        if (addBtn) {
            addBtn.onclick = function() {
                showClientModal(function(c,o){ addClientRow(c,o); });
            };
        }

        var createForm = document.getElementById('createExportForm');
        if (createForm) {
            createForm.addEventListener('submit', function(e) {
                var dir = createForm.export_dir.value.trim();
                if (!dir) {
                    e.preventDefault();
                    showConfirmation('Please select a directory to export.');
                    return;
                }
                var clients = [];
                document.querySelectorAll('#clientTable tbody tr').forEach(function(row) {
                    var c = row.querySelector('.client-cell').textContent.trim();
                    var o = row.querySelector('.opts-cell').textContent.trim();
                    if (c) {
                        if (o) clients.push(c + '(' + o + ')');
                        else clients.push(c);
                    }
                });
                if (clients.length === 0) {
                    e.preventDefault();
                    showConfirmation('Please add at least one client entry.');
                    return;
                }
                createForm.export_line.value = dir + ' ' + clients.join(' ');
            });
        }
    }

    // edit-export helper
    function addEditClientRow(client, opts) {
        var tbody = document.querySelector('#editClientTable tbody');
        if (!tbody) return;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td class="client-cell">'+(client||'')+'</td>' +
                       '<td class="opts-cell">'+(opts||'')+'</td>' +
                       '<td>' +
                         '<button type="button" class="btn btn-sm btn-secondary btn-edit-client">&#9998;</button> ' +
                         '<button type="button" class="btn btn-sm btn-danger btn-del-client">&times;</button>' +
                       '</td>';
        tbody.appendChild(tr);
        tr.querySelector('.btn-del-client').addEventListener('click', function() {
            tr.remove();
        });
        tr.querySelector('.btn-edit-client').addEventListener('click', function() {
            showClientModal(function(c,o){
                tr.querySelector('.client-cell').textContent = c;
                tr.querySelector('.opts-cell').textContent = o;
            }, client, opts);
        });
    }

    var exportsTable = document.getElementById('exportsTable');
    if (exportsTable) {
        exportsTable.querySelectorAll('tbody tr').forEach(function(row) {
            row.addEventListener('click', function() {
                var dir = row.getAttribute('data-dir');
                if (!dir) return;
                var info = window.exportClients && window.exportClients[dir];
                if (!info) info = {clients: []};
                var modal = document.getElementById('editExportModal');
                if (!modal) return;
                var dirLabel = modal.querySelector('#editExportDir');
                if (dirLabel) dirLabel.textContent = dir;
                var commentDiv = modal.querySelector('#editComment');
                if (commentDiv) commentDiv.textContent = info.comment || '';
                var tblBody = modal.querySelector('#editClientTable tbody');
                if (tblBody) tblBody.innerHTML = '';
                info.clients.forEach(function(c) {
                    addEditClientRow(c.client, c.opts);
                });
                var hidden = document.getElementById('replace_dir');
                if (hidden) hidden.value = dir;
                var newLine = document.getElementById('new_line');
                if (newLine) newLine.value = '';
                var rem = document.getElementById('remove_export');
                if (rem) rem.value = '';
                var origInput = document.getElementById('orig_line');
                if (origInput) {
                    var clientsArr = [];
                    info.clients.forEach(function(c){
                        if (c.client) {
                            if (c.opts) clientsArr.push(c.client + '(' + c.opts + ')');
                            else clientsArr.push(c.client);
                        }
                    });
                    origInput.value = dir + (clientsArr.length ? ' ' + clientsArr.join(' ') : '');
                }
                new bootstrap.Modal(modal).show();
            });
        });
    }
    var addEditBtn = document.getElementById('addEditClientBtn');
    if (addEditBtn) {
        addEditBtn.addEventListener('click', function() {
            showClientModal(function(c,o){ addEditClientRow(c,o); });
        });
    }
    var editForm = document.getElementById('editExportForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            var dir = editForm.replace_dir.value.trim();
            if (!dir) return;
            var clients = [];
            editForm.querySelectorAll('#editClientTable tbody tr').forEach(function(row) {
                var c = row.querySelector('.client-cell').textContent.trim();
                var o = row.querySelector('.opts-cell').textContent.trim();
                if (c) {
                    if (o) clients.push(c + '(' + o + ')');
                    else clients.push(c);
                }
            });
            if (clients.length === 0) {
                e.preventDefault();
                showConfirmation('Please add at least one client entry.');
                return;
            }
            editForm.new_line.value = dir + ' ' + clients.join(' ');
        });

        var delBtn = document.getElementById('deleteExportBtn');
        if (delBtn) {
            delBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var orig = editForm.orig_line.value.trim();
                if (!orig) return;
                showConfirmation('Delete this export entry? This will remove the entire export from ' + (window.CONFIG && window.CONFIG.exportsFile ? window.CONFIG.exportsFile : '/etc/exports') + '.', function() {
                    editForm.remove_export.value = orig;
                    editForm.submit();
                });
            });
        }
    }
});
