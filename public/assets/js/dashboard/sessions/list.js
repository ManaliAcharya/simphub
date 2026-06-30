"use strict";

const ActiveSessions = (() => {
    let table;

    const getDataTable = () => {
        if (!table) {
            table = $("#active-sessions-table").DataTable();
        }

        return table;
    };

    const reloadTable = () => {
        getDataTable().ajax.reload(null, false);
    };

    const showSuccess = (message) => {
        return Swal.fire({
            text: message,
            icon: "success",
            buttonsStyling: false,
            confirmButtonText: "Ok",
            customClass: {
                confirmButton: "btn btn-primary",
            },
        });
    };

    const showError = (message) => {
        return Swal.fire({
            text: message,
            icon: "error",
            buttonsStyling: false,
            confirmButtonText: "Ok",
            customClass: {
                confirmButton: "btn btn-primary",
            },
        });
    };

    const getErrorMessage = (error) => {
        if (error.response?.data?.message) {
            return error.response.data.message;
        }

        return "Something went wrong. Please try again.";
    };

    const handleSessionRevoke = async (button) => {
        const sessionId = button.dataset.sessionId;

        console.log("sessionId==",sessionId);

        const result = await Swal.fire({
            text: "Are you sure you want to sign out this session?",
            icon: "warning",
            showCancelButton: true,
            buttonsStyling: false,
            confirmButtonText: "Yes, sign out",
            cancelButtonText: "Cancel",
            customClass: {
                confirmButton: "btn btn-danger",
                cancelButton: "btn btn-light",
            },
        });

        if (!result.isConfirmed) {
            return;
        }

        button.disabled = true;

        try {
            const response = await axios.post(
                `/account/sessions/${sessionId}/revoke`,
                {},
                {
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                }
            );

            await showSuccess(response.data.message);

            reloadTable();
        } catch (error) {
            showError(getErrorMessage(error));
        } finally {
            button.disabled = false;
        }
    };

    const handleLogoutOthers = async () => {
        const result = await Swal.fire({
            text: "Sign out all other active sessions?",
            icon: "warning",
            showCancelButton: true,
            buttonsStyling: false,
            confirmButtonText: "Yes, sign out all",
            cancelButtonText: "Cancel",
            customClass: {
                confirmButton: "btn btn-danger",
                cancelButton: "btn btn-light",
            },
        });

        if (!result.isConfirmed) {
            return;
        }

        const button = document.querySelector(
            "#kt_logout_other_sessions"
        );

        if (button) {
            button.disabled = true;
            button.setAttribute("data-kt-indicator", "on");
        }

        try {
            const response = await axios.post(
                "/account/sessions/logout-others",
                {},
                {
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                }
            );

            await showSuccess(response.data.message);

            reloadTable();
        } catch (error) {
            showError(getErrorMessage(error));
        } finally {
            if (button) {
                button.disabled = false;
                button.removeAttribute("data-kt-indicator");
            }
        }
    };

    const initEvents = () => {
        document.addEventListener("click", (event) => {
            const revokeButton = event.target.closest(
                ".js-signout-session"
            );

            if (revokeButton) {
                handleSessionRevoke(revokeButton);
                return;
            }

            const logoutOthersButton = event.target.closest(
                "#kt_logout_other_sessions"
            );

            if (logoutOthersButton) {
                handleLogoutOthers();
            }
        });
    };

    return {
        init() {
            initEvents();
        },
    };
})();

KTUtil.onDOMContentLoaded(() => {
    ActiveSessions.init();
});
