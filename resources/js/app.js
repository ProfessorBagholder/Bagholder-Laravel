document.addEventListener('livewire:init', () => {
    Livewire.interceptMessage(({ onFailure, onError }) => {
        onFailure(() => {
            // Network failures stay on the current page; Flux + Livewire already surface request state via data-loading.
        });

        onError(() => {
            // Server errors are handled by Livewire; interceptors replace the v3 commit/request hooks.
        });
    });
});
