import axios from 'axios';

window.axios = axios;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.withCredentials = true;     // session cookie; axios sends XSRF-TOKEN automatically
axios.defaults.withXSRFToken = true;

// Don't auto-redirect on 401: if the page session and API auth ever disagree, a redirect
// bounces between /login and /sites forever. Show a banner and let the user sign in again.
axios.interceptors.response.use(undefined, (err) => {
    if ([401, 419].includes(err.response?.status)) window.dispatchEvent(new CustomEvent('session-expired'));
    return Promise.reject(err);
});
