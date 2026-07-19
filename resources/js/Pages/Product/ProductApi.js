import axios from 'axios';

const ProductApi = {
    async getProducts(params = {}) {
        const response = await axios.get(
            '/app/inventory/products/list',
            {
                params,
            }
        );

        return response.data;
    },

    async saveProduct(payload) {
        const response = await axios.post(
            '/app/inventory/products/save',
            payload
        );

        return response.data;
    },

    async deleteProduct(id) {
        const response = await axios.delete(
            `/app/inventory/products/${id}`
        );

        return response.data;
    },

    async searchHsn(search = '') {
        const response = await axios.get(
            '/app/inventory/hsn-search',
            {
                params: {
                    q: search,
                },
            }
        );

        return response.data;
    },
};

export default ProductApi;