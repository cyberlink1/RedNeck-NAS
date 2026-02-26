// Samba share view specific behavior

document.addEventListener('DOMContentLoaded', function() {
    // removal confirmations for share buttons
    document.querySelectorAll('.btn-remove-share').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var form = btn.closest('form');
            showConfirmation('Remove this Samba share? This will update smb.conf.', function() {
                form.submit();
            });
        });
    });

    var createShareModal = document.getElementById('createShareModal');
    if (createShareModal) {
        createShareModal.addEventListener('show.bs.modal', function() {
            var form = document.getElementById('createShareForm');
            if (form) form.reset();
            var tb = createShareModal.querySelector('.shareOptionTable tbody');
            if (tb) tb.innerHTML = '';
        });
    }
    function addOptionRow(modal, key, val) {
        if (!modal) return;
        var tbody = modal.querySelector('.shareOptionTable tbody');
        if (!tbody) return;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input list="shareOptionNames" type="text" name="opt_name[]" class="form-control form-control-sm" value="'+(key||'')+'"></td>' +
                       '<td><input type="text" name="opt_val[]" class="form-control form-control-sm" value="'+(val||'')+'"></td>' +
                       '<td><button type="button" class="btn btn-sm btn-danger btn-del-opt">&times;</button></td>';
        tbody.appendChild(tr);
        tr.querySelector('.btn-del-opt').addEventListener('click', function() {
            tr.remove();
        });
    }

    document.querySelectorAll('.addOptionBtn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = btn.closest('.modal');
            addOptionRow(modal);
        });
    });

    var shareTable = document.getElementById('sambaTable');
    if (shareTable) {
        shareTable.querySelectorAll('tbody tr').forEach(function(row) {
            row.addEventListener('click', function() {
                var name = row.getAttribute('data-share');
                if (!name) return;
                var info = window.sambaShares && window.sambaShares[name] || {};
                var modal = document.getElementById('editShareModal');
                if (!modal) return;
                var nameLabel = modal.querySelector('#editShareName');
                if (nameLabel) nameLabel.textContent = name;
                modal.querySelector('#old_share').value = name;
                modal.querySelector('#editShareNameInput').value = name;
                var pathSelect = modal.querySelector('#editSharePath');
                var curPath = info['path'] || '';
                if (pathSelect) {
                    // if the current share path was filtered out of the dropdown,
                    // add it so the value can be set correctly
                    if (curPath && !Array.from(pathSelect.options).some(o=>o.value===curPath)) {
                        var opt = document.createElement('option');
                        opt.value = curPath;
                        opt.textContent = curPath;
                        pathSelect.appendChild(opt);
                    }
                    pathSelect.value = curPath;
                }
                modal.querySelector('#editShareComment').value = info['comment'] || '';
                var tb = modal.querySelector('.shareOptionTable tbody');
                if (tb) {
                    tb.innerHTML = '';
                    Object.keys(info).forEach(function(k) {
                        if (k === 'path' || k === 'comment') return;
                        addOptionRow(modal, k, info[k]);
                    });
                }
                new bootstrap.Modal(modal).show();
            });
        });
    }
    var deleteShareBtn = document.getElementById('deleteShareBtn');
    if (deleteShareBtn) {
        deleteShareBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var form = document.getElementById('editShareForm');
            if (!form) return;
            var name = form.old_share.value.trim();
            if (!name) return;
            showConfirmation('Delete this Samba share? This will update smb.conf.', function() {
                var hidden = form.querySelector('input[name="remove_share_name"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'remove_share_name';
                    form.appendChild(hidden);
                }
                hidden.value = name;
                form.remove_share = '1';
                var flag = document.createElement('input');
                flag.type = 'hidden';
                flag.name = 'remove_share';
                flag.value = '1';
                form.appendChild(flag);
                form.submit();
            });
        });
    }
});
