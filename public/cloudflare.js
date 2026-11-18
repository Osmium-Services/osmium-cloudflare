document.getElementById('purge-everything-form')?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const confirmed = await adminConfirm('Clear everything in the Cloudflare zone? This includes any other site that shares it. Pages are rebuilt on the next visit.');
    if (confirmed) this.submit();
});
