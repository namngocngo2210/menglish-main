<?php

return [
    // Không đưa route chỉ dùng máy chủ ↔ máy chủ ra file route công khai gửi cho mọi trình duyệt.
    'except' => ['deploy.hook'],
];
