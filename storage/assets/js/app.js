import { createApp, ref } from '/assets/vendor/vue3/vue.esm-browser.js';

const initialState = window.__INITIAL_STATE__ || { products: [] };

createApp({
    setup() {
        const products = ref(initialState.products || []);
        const newProduct = ref({ name: '', price: '' });

        const addProduct = () => {
            if (!newProduct.value.name) return;
            products.value.push({
                id: Date.now(),
                name: newProduct.value.name,
                price: parseFloat(newProduct.value.price) || 0
            });
            newProduct.value = { name: '', price: '' };
        };

        return { products, newProduct, addProduct };
    }
}).mount('#app');