import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('promotionEditor', (config) => ({
    endpoint: config.endpoint,
    scope: config.scope,
    type: config.type,
    status: config.status,
    originalStatus: config.originalStatus,
    submitting: false,
    selectedProducts: config.selectedProducts ?? [],
    selectedCategories: config.selectedCategories ?? [],
    buyProduct: config.buyProduct,
    giftProduct: config.giftProduct,
    buyVariant: config.buyVariant,
    giftVariant: config.giftVariant,
    queries: { selectedProducts: '', selectedCategories: '', buyProduct: '', giftProduct: '', buyVariant: '', giftVariant: '' },
    results: { selectedProducts: [], selectedCategories: [], buyProduct: [], giftProduct: [], buyVariant: [], giftVariant: [] },
    async search(kind) {
        const productId = kind === 'buyVariant' ? this.buyProduct?.id : kind === 'giftVariant' ? this.giftProduct?.id : null;
        if ((kind === 'buyVariant' || kind === 'giftVariant') && !productId) {
            this.results[kind] = [];
            return;
        }
        const url = new URL(this.endpoint, window.location.origin);
        url.searchParams.set('type', kind === 'selectedCategories' ? 'categories' : kind.endsWith('Variant') ? 'variants' : 'products');
        url.searchParams.set('search', this.queries[kind]);
        if (productId) url.searchParams.set('product_id', productId);
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        this.results[kind] = response.ok ? (await response.json()).data : [];
    },
    choose(kind, item) {
        if (kind === 'selectedProducts' || kind === 'selectedCategories') {
            if (!this[kind].some((selected) => selected.id === item.id)) this[kind].push(item);
        } else {
            this[kind] = item;
            if (kind === 'buyProduct') this.buyVariant = null;
            if (kind === 'giftProduct') this.giftVariant = null;
        }
        this.results[kind] = [];
        this.queries[kind] = '';
    },
    remove(kind, id = null) {
        if (kind === 'selectedProducts' || kind === 'selectedCategories') {
            this[kind] = this[kind].filter((item) => item.id !== id);
        } else {
            this[kind] = null;
            if (kind === 'buyProduct') this.buyVariant = null;
            if (kind === 'giftProduct') this.giftVariant = null;
        }
    },
}));

Alpine.start();
