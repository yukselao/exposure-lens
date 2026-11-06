/**
 * HTTP client for Tenable Identity Exposure API
 */

import axios, { AxiosInstance, AxiosError, AxiosRequestConfig } from 'axios';
import type { TIEConfig } from './config.js';

export class TIEUpstreamError extends Error {
  constructor(message: string, readonly upstreamStatus?: number) {
    super(message);
  }
}

export class TIEClient {
  private client: AxiosInstance;

  constructor(config: TIEConfig) {
    this.client = axios.create({
      baseURL: config.baseUrl,
      timeout: config.timeout,
      maxRedirects: 0,
      headers: {
        'x-api-key': config.apiKey,
        'Content-Type': 'application/json',
      },
    });

    // Add response interceptor for error handling
    this.client.interceptors.response.use(
      (response) => response,
      (error: AxiosError) => {
        return Promise.reject(this.formatError(error));
      }
    );
  }

  /**
   * GET request
   */
  async get<T>(path: string, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.client.get<T>(path, config);
    return response.data;
  }

  /**
   * POST request
   */
  async post<T>(path: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.client.post<T>(path, data, config);
    return response.data;
  }

  /**
   * PATCH request
   */
  async patch<T>(path: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.client.patch<T>(path, data, config);
    return response.data;
  }

  /**
   * PUT request
   */
  async put<T>(path: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.client.put<T>(path, data, config);
    return response.data;
  }

  /**
   * DELETE request
   */
  async delete<T>(path: string, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.client.delete<T>(path, config);
    return response.data;
  }

  /**
   * Format axios errors into user-friendly messages
   */
  private formatError(error: AxiosError): TIEUpstreamError {
    const detail = error.response ? `HTTP ${error.response.status}` : error.code ?? 'request failed';
    return new TIEUpstreamError(`TIE API request failed (${detail})`, error.response?.status);
  }
}
