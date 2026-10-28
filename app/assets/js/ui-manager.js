/**
 * UI Manager - Centraliza todas las interacciones de SweetAlert2
 * Uso: UI.success('Titulo', 'Mensaje');
 */
const UI = {
    // Colores corporativos (Configúralos aquí)
    colors: {
        primary: '#0d6efd',
        danger: '#dc3545',
        success: '#198754',
        warning: '#ffc107'
    },

    // 1. Cargando (Loader)
    loading: (title = 'Procesando...') => {
        Swal.fire({
            title: title,
            html: '<div class="spinner-border text-primary"></div><p class="mt-2">Por favor espere</p>',
            showConfirmButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false
        });
    },

    // 2. Cerrar alerta actual
    close: () => Swal.close(),

    // 3. Alerta de Éxito
    success: (title, message, callback = null) => {
        Swal.fire({
            icon: 'success',
            title: title,
            text: message,
            confirmButtonColor: UI.colors.success,
            confirmButtonText: 'Aceptar'
        }).then((result) => {
            if (callback) callback();
        });
    },

    // 4. Alerta de Error
    error: (title, message) => {
        Swal.fire({
            icon: 'error',
            title: title,
            text: message,
            confirmButtonColor: UI.colors.danger
        });
    },

    // 5. Confirmación (Si/No) - Para eliminar o acciones críticas
    confirm: (title, message, confirmText = 'Sí, continuar', callback) => {
        Swal.fire({
            title: title,
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: UI.colors.primary,
            cancelButtonColor: UI.colors.danger,
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                callback();
            }
        });
    },

    // 6. Modal Genérico con Formulario HTML
    // params: { title, html, width, preConfirmFn, didOpenFn }
    modalForm: (params) => {
        Swal.fire({
            title: params.title || 'Formulario',
            html: params.html,
            width: params.width || '600px',
            showCancelButton: true,
            confirmButtonText: params.confirmText || 'Guardar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: UI.colors.primary,
            allowOutsideClick: false,
            didOpen: () => {
                if (params.didOpenFn) params.didOpenFn();
            },
            preConfirm: () => {
                if (params.preConfirmFn) {
                    return params.preConfirmFn();
                }
            }
        }).then((result) => {
            if (result.isConfirmed && params.callback) {
                params.callback(result.value);
            }
        });
    }
};