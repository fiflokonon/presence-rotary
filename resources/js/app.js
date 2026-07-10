import Alpine from 'alpinejs';
import QRCode from 'qrcode';

window.Alpine = Alpine;

Alpine.data('attendanceDashboard', (records) => ({
    records,
    search: '',
    activeCategory: 'all',
    get filtered() {
        const search = this.search.toLowerCase();

        return this.records.filter((record) => {
            const matchesCategory = this.activeCategory === 'all' || record.category === this.activeCategory;
            const matchesSearch = record.name.toLowerCase().includes(search);

            return matchesCategory && matchesSearch;
        });
    },
    get groups() {
        const order = ['officials', 'members', 'rotaractors', 'guests'];

        return order
            .map((category) => ({
                category,
                records: this.filtered.filter((record) => record.category === category),
            }))
            .filter((group) => group.records.length > 0);
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

Alpine.data('qrCodePanel', (url) => ({
    url,
    open: false,
    rendered: false,
    copied: false,
    toggle() {
        this.open = !this.open;

        if (this.open && !this.rendered) {
            QRCode.toCanvas(this.$refs.canvas, this.url);
            this.rendered = true;
        }
    },
    async share() {
        if (navigator.share) {
            await navigator.share({ url: this.url });
            return;
        }

        await navigator.clipboard.writeText(this.url);
        this.copied = true;
        setTimeout(() => {
            this.copied = false;
        }, 2000);
    },
    download() {
        const link = document.createElement('a');
        link.href = this.$refs.canvas.toDataURL('image/png');
        link.download = 'qr-code-emargement.png';
        link.click();
    },
}));

Alpine.start();
