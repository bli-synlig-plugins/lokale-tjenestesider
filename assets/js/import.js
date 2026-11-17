(function () {
    var drop = document.querySelector('[data-lts-drop]');
    var input = document.getElementById('lts_json');
    var name = document.querySelector('[data-lts-drop-name]');

    function showFile() {
        if (!input || !name || !drop) {
            return;
        }
        var file = input.files && input.files[0];
        if (!file) {
            name.hidden = true;
            name.textContent = '';
            drop.classList.remove('has-file');
            return;
        }
        name.hidden = false;
        name.textContent = file.name;
        drop.classList.add('has-file');
    }

    if (input) {
        input.addEventListener('change', showFile);
    }

    if (drop) {
        drop.addEventListener('dragover', function (event) {
            event.preventDefault();
            drop.classList.add('is-over');
        });
        drop.addEventListener('dragleave', function () {
            drop.classList.remove('is-over');
        });
        drop.addEventListener('drop', function (event) {
            event.preventDefault();
            drop.classList.remove('is-over');
            if (!input || !event.dataTransfer || !event.dataTransfer.files || !event.dataTransfer.files.length) {
                return;
            }
            input.files = event.dataTransfer.files;
            showFile();
        });
    }
}());
