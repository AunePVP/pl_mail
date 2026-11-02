**The writing model's reasoning can be switched off in Admin → AI.** A new box under the writing
model, on by default, asks the model to answer straight away instead of thinking first: replies,
sorting and summaries come back sooner, usually a little less carefully. It is sent as `think: false`
to Ollama and as `chat_template_kwargs.enable_thinking: false` to an OpenAI-compatible server such
as vLLM, SGLang or llama.cpp; while it is on, requests are exactly as before. Adds the table
`fork_ai_options`.
