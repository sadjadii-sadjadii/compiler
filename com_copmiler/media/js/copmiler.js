document.addEventListener('DOMContentLoaded', () => {
    const dropzone = document.getElementById('copmiler-dropzone');
    const fileInput = document.getElementById('wp_plugin_zip');
    const fileLabel = document.getElementById('copmiler-file-label');
    const form = document.getElementById('copmiler-upload-form');
    const submitBtn = document.getElementById('copmiler-submit-btn');

    if (dropzone && fileInput && fileLabel) {
        ['dragenter', 'dragover'].forEach((evt) => {
            dropzone.addEventListener(evt, () => dropzone.classList.add('is-dragover'));
        });
        ['dragleave', 'drop'].forEach((evt) => {
            dropzone.addEventListener(evt, () => dropzone.classList.remove('is-dragover'));
        });
        fileInput.addEventListener('change', () => {
            if (fileInput.files && fileInput.files.length > 0) {
                const f = fileInput.files[0];
                fileLabel.textContent = `${f.name} (${(f.size / 1024).toFixed(1)} KB)`;
            }
        });
    }

    if (form && submitBtn) {
        form.addEventListener('submit', () => {
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-75');
        });
    }
});
