const ApiService = Shopware.Classes.ApiService;
const { Application } = Shopware;

class SplacApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'splac') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'splacApiService';
    }

    createProcess(payload) {
        return this.httpClient
            .post('/_action/splac/process', payload, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    uploadSource(processId, file) {
        const formData = new FormData();
        formData.append('file', file, file.name);

        return this.httpClient
            .post(`/_action/splac/process/${processId}/source`, formData, {
                headers: this.getBasicHeaders({ 'Content-Type': 'multipart/form-data' }),
            })
            .then(ApiService.handleResponse);
    }

    start(processId, payload = {}) {
        return this.httpClient
            .post(`/_action/splac/process/${processId}/start`, payload, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    regenerate(processId, step, output = null) {
        return this.httpClient
            .post(`/_action/splac/process/${processId}/regenerate`, { step, output }, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    approve(processId, payload = {}) {
        return this.httpClient
            .post(`/_action/splac/process/${processId}/approve`, payload, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    retry(processId) {
        return this.httpClient
            .post(`/_action/splac/process/${processId}/retry`, {}, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    cancel(processId) {
        return this.httpClient
            .post(`/_action/splac/process/${processId}/cancel`, {}, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    getStatistics() {
        return this.httpClient
            .get('/_action/splac/cost-statistics', { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    getLlmCapabilities() {
        return this.httpClient
            .get('/_action/splac/llm-capabilities', { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }
}

Application.addServiceProvider('splacApiService', (container) => {
    const initContainer = Application.getContainer('init');

    return new SplacApiService(initContainer.httpClient, container.loginService);
});

export default SplacApiService;
