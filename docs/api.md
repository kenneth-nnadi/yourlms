# HTTP API

Site instructors can create bearer tokens under **Teach → API tokens**. The API is read-oriented for course metadata and grade export.

## Authentication

```http
Authorization: Bearer ylms_<token>
```

Apache deployments require the root `.htaccess` rules that forward the `Authorization` header to PHP.

For debugging, you may pass `?api_token=ylms_...` as a query parameter (prefer the header in production).

## Endpoints

Base path: `/api/index.php?route=`

| Route | Method | Description |
|-------|--------|-------------|
| `courses` | GET | List all courses |
| `courses/{id}` | GET | Course metadata |
| `courses/{id}/students` | GET | Enrolled students |
| `courses/{id}/grades` | GET | Grade export payload (CSV embedded in JSON) |

### Example

```bash
curl -H "Authorization: Bearer ylms_YOUR_TOKEN" \
  "http://localhost/yourlms/api/index.php?route=courses"
```

Response:

```json
{
  "courses": [
    { "id": 1, "code": "CS101", "name": "...", "term": "...", "published": 1 }
  ]
}
```

## Errors

| Code | Meaning |
|------|---------|
| 401 | Missing or invalid token |
| 403 | Token valid but user is not a site instructor |
| 404 | Unknown route or course |

## Security notes

- Rotate tokens periodically; delete unused tokens in the admin UI.
- Do not commit tokens to git or embed them in client-side JavaScript.
- Terminate TLS at the reverse proxy for production deployments.