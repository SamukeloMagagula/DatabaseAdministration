// Confirm a destructive submit (set data-confirm on the <form>), then guard
// against a slow request being fired twice by disabling the submit button.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;

    const message = form.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
        return;
    }

    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
});

// SQL console: Tab indents instead of leaving the textarea, Ctrl/Cmd+Enter runs it.
document.querySelectorAll('textarea[name="sql"]').forEach((textarea) => {
    textarea.addEventListener('keydown', (event) => {
        if (event.key === 'Tab') {
            event.preventDefault();
            const { selectionStart: start, selectionEnd: end, value } = textarea;
            textarea.value = value.slice(0, start) + '\t' + value.slice(end);
            textarea.selectionStart = textarea.selectionEnd = start + 1;
            return;
        }
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            textarea.form?.requestSubmit();
        }
    });
});
