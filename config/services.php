<?php
return ['ai'=>['provider'=>env('AI_PROVIDER'),'base_url'=>env('AI_BASE_URL','https://api.openai.com/v1'),'model'=>env('AI_MODEL','gpt-4o-mini')]];