import os
from dotenv import load_dotenv

load_dotenv()

ENV = os.environ.get("ENV", "http://localhost:8074")
