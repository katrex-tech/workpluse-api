"""initial face schema with pgvector

Revision ID: 001_initial
Revises: 
Create Date: 2026-10-27 21:00:00.000000

"""
from alembic import op
import sqlalchemy as sa

revision = '001_initial'
down_revision = None
branch_labels = None
depends_on = None

def upgrade() -> None:
    # 1. Enable pgvector extension if running on PostgreSQL
    conn = op.get_bind()
    if conn.dialect.name == "postgresql":
        op.execute("CREATE EXTENSION IF NOT EXISTS vector")

    # 2. Create face_enrollments table
    op.create_table(
        'face_enrollments',
        sa.Column('id', sa.Integer(), autoincrement=True, nullable=False),
        sa.Column('tenant_id', sa.String(length=64), nullable=False),
        sa.Column('user_id', sa.String(length=64), nullable=False),
        sa.Column('embedding', sa.Text(), nullable=False),
        sa.Column('thumbnail_path', sa.String(length=255), nullable=True),
        sa.Column('thumbnail_mime', sa.String(length=32), nullable=True),
        sa.Column('model_name', sa.String(length=64), nullable=False),
        sa.Column('model_checksum', sa.String(length=64), nullable=False),
        sa.Column('embedding_dim', sa.Integer(), nullable=False),
        sa.Column('normalization', sa.String(length=32), nullable=False),
        sa.Column('alignment_config', sa.String(length=64), nullable=False),
        sa.Column('pipeline_version', sa.String(length=32), nullable=False),
        sa.Column('consent_given', sa.Boolean(), nullable=False),
        sa.Column('idempotency_key', sa.String(length=128), nullable=True),
        sa.Column('is_active', sa.Boolean(), nullable=False),
        sa.Column('created_at', sa.DateTime(), nullable=False),
        sa.Column('updated_at', sa.DateTime(), nullable=False),
        sa.PrimaryKeyConstraint('id'),
        sa.UniqueConstraint('tenant_id', 'user_id', name='uq_tenant_user_face')
    )
    op.create_index('idx_tenant_user_active', 'face_enrollments', ['tenant_id', 'user_id', 'is_active'])

    # 3. Create face_audit_logs table
    op.create_table(
        'face_audit_logs',
        sa.Column('id', sa.Integer(), autoincrement=True, nullable=False),
        sa.Column('tenant_id', sa.String(length=64), nullable=False),
        sa.Column('user_id', sa.String(length=64), nullable=True),
        sa.Column('action', sa.String(length=32), nullable=False),
        sa.Column('decision', sa.String(length=32), nullable=True),
        sa.Column('matched_user_id', sa.String(length=64), nullable=True),
        sa.Column('score', sa.Float(), nullable=True),
        sa.Column('runner_up_score', sa.Float(), nullable=True),
        sa.Column('faces_detected_count', sa.Integer(), nullable=True),
        sa.Column('processing_time_ms', sa.Float(), nullable=False),
        sa.Column('ip_address', sa.String(length=45), nullable=True),
        sa.Column('created_at', sa.DateTime(), nullable=False),
        sa.PrimaryKeyConstraint('id')
    )
    op.create_index('idx_audit_tenant_action', 'face_audit_logs', ['tenant_id', 'action', 'created_at'])

def downgrade() -> None:
    op.drop_table('face_audit_logs')
    op.drop_table('face_enrollments')
