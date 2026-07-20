### register curl command

```bash
gourangas-MacBook-Air ~  ➜ curl -X POST http://127.0.0.1:8000/api/register \
-H "Content-Type: application/json" \
-H "Accept: application/json" \
-d '{
    "organization_name": "Acme Software",
    "name": "Gouranga Ghosh",
    "email": "gouranga@example.com",
    "password": "Password@123"
}'
{"status":true,"message":"Registration successful.","data":{"tenant":{"uuid":"d30e7be8-87d3-4ea1-8f8d-ef005fef522a","name":"Acme Software","slug":"acme-software","status":"trial","timezone":"UTC","data_region":"us","retention_days":365,"billing_email":"gouranga@example.com","updated_at":"2026-07-20T20:33:26.000000Z","created_at":"2026-07-20T20:33:26.000000Z","id":1},"user":{"tenant_id":1,"name":"Gouranga Ghosh","email":"gouranga@example.com","role":"owner","status":"active","updated_at":"2026-07-20T20:33:26.000000Z","created_at":"2026-07-20T20:33:26.000000Z","id":1},"token":"1|6wizhO01HjpYBaGTkjhHau2GQpS8w4zpQ7B6ktTF98fe3771"},"meta":{"correlation_id":"req_6a5e861667252","timestamp":"2026-07-20T20:33:26.422494Z"}}%
```
