import Alpine from 'alpinejs';
import QRCode from 'qrcode';

window.Alpine = Alpine;

Alpine.data('attendanceDashboard', (records) => ({
    records,
    search: '',
    activeCategory: 'all',
    activeTitle: 'all',
    activeMiscFilter: 'all',
    sortMode: 'grouped',
    get titleOptions() {
        return [...new Set(this.records.map((record) => record.title))].sort();
    },
    get filtered() {
        const search = this.search.toLowerCase();

        return this.records.filter((record) => {
            const matchesCategory = this.activeCategory === 'all' || record.category === this.activeCategory;
            const matchesTitle = this.activeTitle === 'all' || record.title === this.activeTitle;
            const matchesSearch = record.name.toLowerCase().includes(search);
            const matchesMisc = this.activeMiscFilter === 'all' || 
                (this.activeMiscFilter === 'yes' && record.hasMisc) ||
                (this.activeMiscFilter === 'no' && !record.hasMisc);

            return matchesCategory && matchesTitle && matchesSearch && matchesMisc;
        });
    },
    get groups() {
        const order = ['officials', 'members', 'rotaractors', 'guests'];

        return order
            .map((category) => ({
                category,
                records: this.sortByPosition(this.filtered.filter((record) => record.category === category)),
            }))
            .filter((group) => group.records.length > 0);
    },
    get flatSorted() {
        return this.sortByPosition(this.filtered);
    },
    sortByPosition(records) {
        return [...records].sort((a, b) => {
            const aOrder = a.positionOrder ?? Infinity;
            const bOrder = b.positionOrder ?? Infinity;

            if (aOrder !== bOrder) return aOrder - bOrder;

            return a.name.localeCompare(b.name);
        });
    },
    initials(name) {
        return name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0])
            .join('')
            .toUpperCase();
    },
}));

Alpine.data('sessionsList', (sessions) => ({
    sessions,
    search: '',
    get filtered() {
        const search = this.search.toLowerCase();

        return this.sessions.filter((session) => session.title.toLowerCase().includes(search));
    },
}));

Alpine.data('qrCodePanel', (url) => ({
    url,
    open: false,
    rendered: false,
    copied: false,
    async toggle() {
        this.open = !this.open;

        if (this.open && !this.rendered) {
            await QRCode.toCanvas(this.$refs.canvas, this.url);
            this.rendered = true;
        }
    },
    async share() {
        try {
            if (navigator.share) {
                await navigator.share({ url: this.url });
                return;
            }

            await navigator.clipboard.writeText(this.url);
            this.copied = true;
            setTimeout(() => {
                this.copied = false;
            }, 2000);
        } catch {
            // User cancelled the native share sheet, or the clipboard write
            // was rejected (e.g. non-secure context) — no action needed.
        }
    },
    download() {
        const link = document.createElement('a');
        link.href = this.$refs.canvas.toDataURL('image/png');
        link.download = 'qr-code-emargement.png';
        link.click();
    },
}));

Alpine.data('adminShell', () => ({
    sidebarOpen: false,
    toggle() {
        this.sidebarOpen = !this.sidebarOpen;
    },
    close() {
        this.sidebarOpen = false;
    },
}));

Alpine.data('closeSessionPanel', (initialNextSessionOption) => ({
    open: false,
    sendThankYouEmail: false,
    mentionNextSession: false,
    nextSessionOption: initialNextSessionOption,
    toggle() {
        this.open = !this.open;
    },
}));

Alpine.store('pageLoading', {
    active: false,
});

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');

    if (!link) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;
    if (link.origin !== window.location.origin) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    Alpine.store('pageLoading').active = true;
});

document.addEventListener('submit', () => {
    Alpine.store('pageLoading').active = true;
});

Alpine.start();
