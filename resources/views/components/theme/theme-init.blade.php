
<script>
    try { 
        const t = localStorage.getItem('theme') || 'system';
        const m = matchMedia('(prefers-color-scheme: dark)').matches;
        const d = t === 'dark' || (t === 'system' && m);
        document.documentElement.classList.toggle('dark', d);
        document.documentElement.style.colorScheme = d ? 'dark' : 'light';
    } catch (e) {}
</script>