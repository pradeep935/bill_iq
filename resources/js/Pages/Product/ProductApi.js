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

    async getProduct(id) {
        const response = await axios.get(
            `/app/inventory/products/${id}`
        );

        return response.data;
    },

    async getReferences() {
        const response = await axios.get(
            '/app/inventory/products/references'
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

    async uploadImage(file) {
        const payload = new FormData();
        payload.append('photo', file);

        const response = await axios.post(
            '/upload/photo',
            payload,
            {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            }
        );

        return response.data;
    },

    async duplicateProduct(id) {
        const response = await axios.post(
            `/app/inventory/products/${id}/duplicate`
        );

        return response.data;
    },

    async bulkStatus(ids, status) {
        const response = await axios.patch(
            '/app/inventory/products/bulk-status',
            {
                ids,
                status,
            }
        );

        return response.data;
    },

    async deleteProduct(id) {
        const response = await axios.delete(
            `/app/inventory/products/${id}`
        );

        return response.data;
    },

    async searchHsn(search = '', productType = 'goods') {
        const response = await axios.get(
            '/app/inventory/hsn-search',
            {
                params: {
                    q: search,
                    product_type: productType,
                },
            }
        );

        return response.data;
    },
};

export default ProductApi;
