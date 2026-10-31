import base from '../../tailwind.config.js';

/** Design sandbox: reuse the real app tokens, scan only the static mockups. */
export default {
    ...base,
    content: ['./screens/*.html'],
};
